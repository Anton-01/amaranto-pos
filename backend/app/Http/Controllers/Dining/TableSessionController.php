<?php

namespace App\Http\Controllers\Dining;

use App\Builders\TicketBuilder;
use App\Exceptions\TableConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TableSession\AddTableItemsRequest;
use App\Http\Requests\TableSession\CancelTableRequest;
use App\Http\Requests\TableSession\CloseTableRequest;
use App\Http\Requests\TableSession\OpenTableRequest;
use App\Http\Requests\TableSession\UpdateTableItemRequest;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\GlobalSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\StockMovement;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TicketConfig;
use App\Models\User;
use App\Services\CancellationAuthorizationService;
use App\Services\OrderCalculator;
use App\Services\PrinterService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una mesa: apertura, comandas sucesivas y cobro.
 *
 * Las tres operaciones son criticas y corren dentro de DB::transaction con
 * `lockForUpdate()` sobre la fila de la mesa, que actua como el punto unico de
 * serializacion del comedor: dos meseros que tocan la misma mesa a la vez se
 * formaran uno detras del otro en lugar de duplicar sesiones o comandas.
 *
 * La ultima linea de defensa no es el codigo sino el motor: el indice unico
 * parcial `table_sessions_one_open_per_table` hace fisicamente imposible que
 * una mesa tenga dos cuentas abiertas.
 */
class TableSessionController extends Controller
{
    public function __construct(
        private readonly OrderCalculator $calculator,
        private readonly TicketBuilder $ticketBuilder,
        private readonly PrinterService $printerService,
        private readonly CancellationAuthorizationService $authorization,
    ) {}

    /**
     * POST /api/tables/{table}/open
     *
     * Abre la mesa, la vincula al mesero y genera la orden base en estado
     * 'open' que ira acumulando el consumo.
     */
    public function open(OpenTableRequest $request, Table $table): JsonResponse
    {
        $activeConfig = TicketConfig::where('is_active', true)->first();

        if (!$activeConfig) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TICKET_NO_ACTIVE_CONFIG',
                'message' => 'No hay una configuracion de ticket activa. Configure un diseno de ticket antes de abrir mesas.',
            ], 422);
        }

        if (!$table->is_active) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TABLE_INACTIVE',
                'message' => 'Esta mesa esta dada de baja y no puede recibir consumos.',
            ], 422);
        }

        $user = $request->user();

        try {
            $session = DB::transaction(function () use ($request, $table, $activeConfig, $user) {
                /** @var Table $locked */
                $locked = Table::whereKey($table->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== Table::STATUS_AVAILABLE) {
                    throw new TableConflictException(
                        $locked->status === Table::STATUS_OCCUPIED ? 'ERR_TABLE_ALREADY_OPEN' : 'ERR_TABLE_NOT_AVAILABLE',
                        $locked->status === Table::STATUS_OCCUPIED
                            ? 'La mesa ya tiene una cuenta abierta.'
                            : 'La mesa esta reservada. Libera la reserva antes de abrirla.'
                    );
                }

                // La cuenta nace vacia y sin descuento: el descuento global se
                // aplica una sola vez, en el cobro.
                $order = Order::create([
                    'ticket_config_id' => $activeConfig->id,
                    'table_id' => $locked->id,
                    'table_name_at_sale' => $locked->name,
                    'waiter_id' => $user->id,
                    'waiter_name_at_sale' => $user->name,
                    'discount_type' => 'none',
                    'discount_value' => 0,
                    'discount_total' => 0,
                    'subtotal' => 0,
                    'iva_total' => 0,
                    'total' => 0,
                    'status' => Order::STATUS_OPEN,
                ]);

                $session = TableSession::create([
                    'table_id' => $locked->id,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'guests' => $request->input('guests'),
                    'notes' => $request->input('notes'),
                    'status' => TableSession::STATUS_OPEN,
                    'opened_at' => now(),
                ]);

                $locked->update(['status' => Table::STATUS_OCCUPIED]);

                AuditLog::create([
                    'action' => 'table_session_opened',
                    'auditable_type' => 'TableSession',
                    'auditable_id' => $session->id,
                    'user_id' => $user->id,
                    'metadata' => [
                        'table_id' => $locked->id,
                        'table_name' => $locked->name,
                        'order_id' => $order->id,
                        'guests' => $session->guests,
                        'waiter_name' => $user->name,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);

                return $session;
            });
        } catch (TableConflictException $e) {
            return $e->toResponse();
        } catch (QueryException $e) {
            // Carrera perdida contra el indice unico parcial.
            if ($this->isUniqueViolation($e)) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'ERR_TABLE_ALREADY_OPEN',
                    'message' => 'La mesa ya tiene una cuenta abierta.',
                ], 422);
            }
            throw $e;
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->presentSession($session),
            'metadata' => ['message' => 'Mesa abierta exitosamente.'],
        ], 201);
    }

    /**
     * POST /api/tables/{table}/items
     *
     * Agrega una comanda a la cuenta viva. Los items previos no se recalculan
     * porque, sin descuento global, la aritmetica de cada linea es
     * independiente: se preserva intacto su `created_at` y con el la
     * trazabilidad ronda por ronda.
     */
    public function addItems(AddTableItemsRequest $request, Table $table): JsonResponse
    {
        $user = $request->user();

        try {
            $session = DB::transaction(function () use ($request, $table, $user) {
                $session = $this->lockOpenSession($table);

                /** @var Order $order */
                $order = Order::whereKey($session->order_id)->lockForUpdate()->firstOrFail();

                $taxRate = $this->taxRate();
                $existingItems = $order->items()->get();

                $this->assertPromotionLimit($existingItems, $request->input('items', []));

                $newLines = [];
                $addedForAudit = [];

                foreach ($request->input('items') as $item) {
                    /** @var Product $product */
                    $product = Product::whereKey($item['product_id'])->lockForUpdate()->firstOrFail();
                    $quantity = (int) $item['quantity'];

                    if ($product->track_stock && $product->current_stock < $quantity) {
                        throw new TableConflictException(
                            'ERR_POS_INSUFFICIENT_STOCK',
                            "Stock insuficiente para \"{$product->name}\": quedan {$product->current_stock} unidades."
                        );
                    }

                    $promotion = !empty($item['promotion_id']) ? Promotion::findOrFail($item['promotion_id']) : null;

                    $newLines[] = $this->calculator->line($product, $promotion, $quantity);
                    $addedForAudit[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $quantity,
                    ];

                    if ($product->track_stock) {
                        $product->decrement('current_stock', $quantity);
                    }
                }

                // Las lineas nuevas se componen sin descuento global (el de la
                // cuenta abierta siempre es 'none'), por lo que se insertan tal cual.
                $composedNew = $this->calculator->compose($newLines, $taxRate);

                foreach ($composedNew['items'] as $itemData) {
                    $order->items()->create($itemData + ['created_at' => now()]);
                }

                // El total de la orden se recompone sobre el conjunto completo
                // para que subtotal e IVA se despejen una sola vez del gran total.
                $allLines = array_merge(
                    $this->calculator->linesFromOrderItems($existingItems),
                    $newLines
                );
                $composedAll = $this->calculator->compose($allLines, $taxRate);

                $order->update([
                    'subtotal' => $composedAll['subtotal'],
                    'iva_total' => $composedAll['iva_total'],
                    'total' => $composedAll['total'],
                ]);

                AuditLog::create([
                    'action' => 'table_items_added',
                    'auditable_type' => 'TableSession',
                    'auditable_id' => $session->id,
                    'user_id' => $user->id,
                    'metadata' => [
                        'table_id' => $table->id,
                        'table_name' => $table->name,
                        'order_id' => $order->id,
                        'items' => $addedForAudit,
                        'new_total' => $composedAll['total'],
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);

                return $session;
            });
        } catch (TableConflictException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->presentSession($session->fresh()),
            'metadata' => ['message' => 'Comanda agregada a la cuenta.'],
        ]);
    }

    /**
     * PUT /api/tables/{table}/items/{item}
     *
     * Adjusts the quantity of a line already sent to the table.
     *
     * The whole operation runs under the same table lock as the rest of the
     * module, so a waiter raising a quantity and another one lowering it cannot
     * interleave and leave the account out of step with the inventory.
     *
     * Stock moves in the direction of the change: growing the line consumes
     * units exactly like a new order does, shrinking it hands them back with a
     * StockMovement so the adjustment is visible in the inventory ledger. Any
     * reduction is written to audit_logs — that is money already registered on
     * the table walking back out of the account.
     */
    public function updateItem(UpdateTableItemRequest $request, Table $table, string $item): JsonResponse
    {
        $user = $request->user();
        $newQuantity = (int) $request->input('quantity');

        try {
            $session = DB::transaction(function () use ($request, $table, $user, $item, $newQuantity) {
                $session = $this->lockOpenSession($table);

                /** @var Order $order */
                $order = Order::whereKey($session->order_id)->lockForUpdate()->firstOrFail();
                $orderItem = $this->lockAccountItem($order, $item);

                $previousQuantity = (int) $orderItem->quantity;
                $delta = $newQuantity - $previousQuantity;

                // Nothing to do, and nothing worth auditing either.
                if ($delta === 0) {
                    return $session;
                }

                $product = $orderItem->product_id
                    ? Product::whereKey($orderItem->product_id)->lockForUpdate()->first()
                    : null;

                if ($delta > 0 && $product?->track_stock) {
                    if ($product->current_stock < $delta) {
                        throw new TableConflictException(
                            'ERR_POS_INSUFFICIENT_STOCK',
                            "Stock insuficiente para \"{$product->name}\": quedan {$product->current_stock} unidades."
                        );
                    }

                    $product->decrement('current_stock', $delta);
                }

                $previousAmount = (float) $orderItem->final_price_at_sale;

                $composed = $this->recomposeAccount(
                    $order,
                    fn (array $lines) => $this->replaceLine($lines, $orderItem->id, $newQuantity)
                );

                if ($delta < 0) {
                    $returned = abs($delta);
                    $this->returnStock($product, $returned, $orderItem, $user);

                    $refreshed = $orderItem->fresh();

                    AuditLog::create([
                        'action' => 'item_removed_from_table',
                        'auditable_type' => 'TableSession',
                        'auditable_id' => $session->id,
                        'user_id' => $user->id,
                        'metadata' => [
                            'operation' => 'quantity_decreased',
                            'table_id' => $table->id,
                            'table_name' => $table->name,
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'product_id' => $orderItem->product_id,
                            'product_name' => $product?->name ?? 'Producto eliminado',
                            'quantity_before' => $previousQuantity,
                            'quantity_after' => $newQuantity,
                            'quantity_removed' => $returned,
                            // What actually left the account, not a unit price:
                            // this is the figure an auditor reconciles against.
                            'amount_removed' => round($previousAmount - (float) ($refreshed?->final_price_at_sale ?? 0), 2),
                            'stock_returned' => (bool) $product?->track_stock,
                            'new_total' => $composed['total'],
                            'user_name' => $user->name,
                        ],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'created_at' => now(),
                    ]);
                } else {
                    AuditLog::create([
                        'action' => 'table_item_quantity_increased',
                        'auditable_type' => 'TableSession',
                        'auditable_id' => $session->id,
                        'user_id' => $user->id,
                        'metadata' => [
                            'table_id' => $table->id,
                            'table_name' => $table->name,
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'product_id' => $orderItem->product_id,
                            'product_name' => $product?->name ?? 'Producto eliminado',
                            'quantity_before' => $previousQuantity,
                            'quantity_after' => $newQuantity,
                            'quantity_added' => $delta,
                            'new_total' => $composed['total'],
                            'user_name' => $user->name,
                        ],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'created_at' => now(),
                    ]);
                }

                return $session;
            });
        } catch (TableConflictException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->presentSession($session->fresh()),
            'metadata' => ['message' => 'Partida actualizada.'],
        ]);
    }

    /**
     * DELETE /api/tables/{table}/items/{item}
     *
     * Removes a line from the live account and gives its stock back.
     *
     * The row is deleted rather than flagged because an open account is a
     * draft, not a sale: nothing here has been charged yet. The forensic record
     * of what was withdrawn — product, quantity and amount — lives in
     * audit_logs, which is append-only.
     */
    public function destroyItem(Request $request, Table $table, string $item): JsonResponse
    {
        $user = $request->user();

        try {
            $session = DB::transaction(function () use ($request, $table, $user, $item) {
                $session = $this->lockOpenSession($table);

                /** @var Order $order */
                $order = Order::whereKey($session->order_id)->lockForUpdate()->firstOrFail();
                $orderItem = $this->lockAccountItem($order, $item);

                $product = $orderItem->product_id
                    ? Product::whereKey($orderItem->product_id)->lockForUpdate()->first()
                    : null;

                $removedQuantity = (int) $orderItem->quantity;
                $removedAmount = (float) $orderItem->final_price_at_sale;

                $this->returnStock($product, $removedQuantity, $orderItem, $user);

                // Totals are recomposed over the surviving lines first, then the
                // row goes away: the account is never observable without its
                // line but still carrying its amount.
                $composed = $this->recomposeAccount(
                    $order,
                    fn (array $lines) => $this->dropLine($lines, $orderItem->id)
                );

                $orderItem->delete();

                AuditLog::create([
                    'action' => 'item_removed_from_table',
                    'auditable_type' => 'TableSession',
                    'auditable_id' => $session->id,
                    'user_id' => $user->id,
                    'metadata' => [
                        'operation' => 'item_deleted',
                        'table_id' => $table->id,
                        'table_name' => $table->name,
                        'order_id' => $order->id,
                        'order_item_id' => $orderItem->id,
                        'product_id' => $orderItem->product_id,
                        'product_name' => $product?->name ?? 'Producto eliminado',
                        'quantity_before' => $removedQuantity,
                        'quantity_after' => 0,
                        'quantity_removed' => $removedQuantity,
                        'amount_removed' => round($removedAmount, 2),
                        'stock_returned' => (bool) $product?->track_stock,
                        'new_total' => $composed['total'],
                        'user_name' => $user->name,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);

                return $session;
            });
        } catch (TableConflictException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->presentSession($session->fresh()),
            'metadata' => ['message' => 'Partida eliminada de la cuenta.'],
        ]);
    }

    /**
     * POST /api/tables/{table}/cancel
     *
     * Voids the whole live account and frees the table without charging it.
     *
     * Authorization comes from one of two places and the audit record says
     * which: an administrator cancels on the strength of their role, anybody
     * else must type the authorization password configured by the
     * administration — a shared secret that is deliberately not any user's
     * login credential.
     *
     * Everything then happens inside a single transaction, because a partial
     * cancellation is worse than none: it would leave a table occupied forever
     * or a phantom account with no table behind it.
     */
    public function cancel(CancelTableRequest $request, Table $table): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $authorizedBy = $this->authorization->authorize($user, $request->input('authorization_password'));

        if ($authorizedBy === null) {
            // A missing secret is an administration gap, not a bad password:
            // the operator is told what to fix instead of retrying blindly.
            if (! $this->authorization->isConfigured()) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'ERR_CANCEL_PASSWORD_NOT_CONFIGURED',
                    'message' => 'No hay una contrasena de autorizacion configurada. Pide al administrador que la defina en Configuracion del Sistema.',
                ], 422);
            }

            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TABLE_CANCEL_UNAUTHORIZED',
                'message' => 'La contrasena de autorizacion es incorrecta.',
            ], 403);
        }

        try {
            $result = DB::transaction(function () use ($request, $table, $user, $authorizedBy) {
                $session = $this->lockOpenSession($table);

                /** @var Order $order */
                $order = Order::whereKey($session->order_id)->lockForUpdate()->firstOrFail();
                $items = $order->items()->get();

                $canceledAmount = (float) $order->total;
                $itemsSnapshot = [];

                foreach ($items as $orderItem) {
                    $product = $orderItem->product_id
                        ? Product::whereKey($orderItem->product_id)->lockForUpdate()->first()
                        : null;

                    $this->returnStock($product, (int) $orderItem->quantity, $orderItem, $user);

                    $itemsSnapshot[] = [
                        'order_item_id' => $orderItem->id,
                        'product_id' => $orderItem->product_id,
                        'product_name' => $product?->name ?? 'Producto eliminado',
                        'quantity' => (int) $orderItem->quantity,
                        'amount' => (float) $orderItem->final_price_at_sale,
                        'stock_returned' => (bool) $product?->track_stock,
                    ];
                }

                // Lines are stamped, never deleted: the voided consumption is
                // the evidence of what was on the table when it was canceled.
                $now = now();
                OrderItem::where('order_id', $order->id)->update(['canceled_at' => $now]);

                $order->update([
                    'status' => Order::STATUS_CANCELED,
                    'canceled_by' => $user->id,
                    'canceled_at' => $now,
                    'cancellation_reason' => $request->input('reason'),
                ]);

                $session->update([
                    'status' => TableSession::STATUS_CANCELED,
                    'closed_at' => $now,
                    'closed_by' => $user->id,
                ]);

                // The partial unique index only guards open sessions, so moving
                // this one to 'canceled' is what actually frees the table for
                // the next party.
                Table::whereKey($table->id)->update(['status' => Table::STATUS_AVAILABLE]);

                AuditLog::create([
                    'action' => 'table_canceled',
                    'auditable_type' => 'TableSession',
                    'auditable_id' => $session->id,
                    'user_id' => $user->id,
                    'metadata' => [
                        'table_id' => $table->id,
                        'table_name' => $table->name,
                        'order_id' => $order->id,
                        'reason' => $request->input('reason'),
                        'canceled_amount' => round($canceledAmount, 2),
                        'items_count' => $items->count(),
                        'items' => $itemsSnapshot,
                        'executed_by' => $user->name,
                        'executed_by_email' => $user->email,
                        // Which authority released the table: the role itself or
                        // the shared authorization password.
                        'authorized_by' => $authorizedBy,
                        'waiter_name' => $order->waiter_name_at_sale,
                        'opened_at' => $session->opened_at?->toIso8601String(),
                        'elapsed_minutes' => $session->opened_at ? (int) $session->opened_at->diffInMinutes($now) : 0,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => $now,
                ]);

                return [
                    'session_id' => $session->id,
                    'order_id' => $order->id,
                    'table_id' => $table->id,
                    'canceled_amount' => round($canceledAmount, 2),
                    'items_count' => $items->count(),
                    'authorized_by' => $authorizedBy,
                ];
            });
        } catch (TableConflictException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
            'metadata' => ['message' => 'Mesa cancelada. La mesa quedo libre y el consumo fue anulado.'],
        ]);
    }

    /**
     * POST /api/tables/{table}/close
     *
     * Cobra la cuenta: aplica el descuento global, sella la orden como venta,
     * abona el cajon del cobrador y libera la mesa.
     */
    public function close(CloseTableRequest $request, Table $table): JsonResponse
    {
        $user = $request->user();

        // El dinero entra al cajon de quien cobra, no al del mesero que abrio.
        $cashRegister = CashRegister::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (!$cashRegister) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_POS_CASH_REGISTER_REQUIRED',
                'message' => 'Debes abrir una caja antes de cobrar una mesa. Realiza la apertura de turno desde el Punto de Venta.',
            ], 422);
        }

        try {
            $order = DB::transaction(function () use ($request, $table, $user, $cashRegister) {
                $session = $this->lockOpenSession($table);

                /** @var Order $order */
                $order = Order::whereKey($session->order_id)->lockForUpdate()->firstOrFail();
                $items = $order->items()->get();

                if ($items->isEmpty()) {
                    throw new TableConflictException(
                        'ERR_TABLE_EMPTY_ORDER',
                        'La mesa no tiene consumos registrados. Agrega productos antes de cobrar.'
                    );
                }

                $taxRate = $this->taxRate();
                $discountType = $request->input('discount_type', 'none');
                $discountValue = (float) $request->input('discount_value', 0);

                $composed = $this->calculator->compose(
                    $this->calculator->linesFromOrderItems($items),
                    $taxRate,
                    $discountType,
                    $discountValue
                );

                // Se actualizan los items en sitio (via `ref`) para repartir el
                // descuento global sin borrarlos: su created_at es la comanda.
                foreach ($composed['items'] as $composedItem) {
                    OrderItem::whereKey($composedItem['ref'])->update([
                        'base_price_at_sale' => $composedItem['base_price_at_sale'],
                        'discount_amount_at_sale' => $composedItem['discount_amount_at_sale'],
                        'final_price_at_sale' => $composedItem['final_price_at_sale'],
                        'tax_amount_at_sale' => $composedItem['tax_amount_at_sale'],
                    ]);
                }

                // El ticket se emite con el diseno vigente al momento del cobro.
                $activeConfig = TicketConfig::where('is_active', true)->first();

                $order->fill([
                    'cash_register_id' => $cashRegister->id,
                    'ticket_config_id' => $activeConfig?->id ?? $order->ticket_config_id,
                    'payment_method_id' => $request->payment_method_id,
                    'promotion_id' => $request->input('promotion_id'),
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_total' => $composed['discount_total'],
                    'subtotal' => $composed['subtotal'],
                    'iva_total' => $composed['iva_total'],
                    'total' => $composed['total'],
                    'amount_received' => $request->amount_received,
                    'amount_change' => $request->amount_change,
                    'custom_legend' => $request->custom_legend,
                    'status' => Order::STATUS_COMPLETED,
                ]);

                // El ingreso se reconoce en el cobro, igual que en mostrador: asi
                // los reportes por fecha y los cortes del dia cuadran. La hora de
                // apertura queda preservada en table_sessions.opened_at.
                $order->created_at = now();
                $order->save();

                $cashRegister->increment('expected_closing_balance', $composed['total']);

                $session->update([
                    'status' => TableSession::STATUS_CLOSED,
                    'closed_at' => now(),
                    'closed_by' => $user->id,
                ]);

                Table::whereKey($table->id)->update(['status' => Table::STATUS_AVAILABLE]);

                AuditLog::create([
                    'action' => 'table_session_closed',
                    'auditable_type' => 'TableSession',
                    'auditable_id' => $session->id,
                    'user_id' => $user->id,
                    'metadata' => [
                        'table_id' => $table->id,
                        'table_name' => $table->name,
                        'order_id' => $order->id,
                        'waiter_name' => $order->waiter_name_at_sale,
                        'cashier_name' => $user->name,
                        'items_count' => $items->count(),
                        'discount_total' => $composed['discount_total'],
                        'total' => $composed['total'],
                        'opened_at' => $session->opened_at?->toIso8601String(),
                        'elapsed_minutes' => $session->opened_at ? (int) $session->opened_at->diffInMinutes(now()) : 0,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);

                return $order;
            });
        } catch (TableConflictException $e) {
            return $e->toResponse();
        }

        $order->load(['items.product', 'items.promotion', 'ticketConfig', 'paymentMethod', 'cashRegister.user', 'promotion', 'table', 'waiter:id,name']);

        $printerData = null;
        if ($order->ticketConfig) {
            try {
                $dto = $this->ticketBuilder->build($order, $order->ticketConfig);
                $printerData = $this->printerService->generateBase64($dto);
            } catch (\Throwable) {
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $order,
            'printer_data' => $printerData,
            'metadata' => ['message' => 'Cuenta cobrada. La mesa quedo libre.'],
        ]);
    }

    /**
     * Bloquea la mesa y devuelve su sesion abierta, o falla con un conflicto.
     */
    private function lockOpenSession(Table $table): TableSession
    {
        Table::whereKey($table->id)->lockForUpdate()->firstOrFail();

        $session = TableSession::where('table_id', $table->id)
            ->where('status', TableSession::STATUS_OPEN)
            ->lockForUpdate()
            ->first();

        if (!$session) {
            throw new TableConflictException(
                'ERR_TABLE_NO_OPEN_SESSION',
                'La mesa no tiene una cuenta abierta.'
            );
        }

        return $session;
    }

    /**
     * Locks a line of the live account, refusing anything outside this order.
     *
     * Scoping the lookup to the order (instead of trusting the id alone) is
     * what stops a crafted request from editing another table's consumption
     * through this endpoint.
     */
    private function lockAccountItem(Order $order, string $itemId): OrderItem
    {
        $item = OrderItem::where('order_id', $order->id)
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw new TableConflictException(
                'ERR_TABLE_ITEM_NOT_FOUND',
                'La partida ya no existe en la cuenta de esta mesa.',
                404
            );
        }

        return $item;
    }

    /**
     * Rebuilds and persists the account after a line was changed or dropped.
     *
     * The mutation is expressed as a callable over the raw lines so the caller
     * decides *what* changed while the arithmetic stays in one place. An open
     * account never carries a global discount, so recomposing every line is
     * lossless: untouched lines resolve to exactly the values already stored.
     *
     * @param  callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>  $mutate
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, iva_total: float, total: float, discount_total: float}
     */
    private function recomposeAccount(Order $order, callable $mutate): array
    {
        $lines = $mutate($this->calculator->linesFromOrderItems($order->items()->get()));

        $composed = $this->calculator->compose($lines, $this->taxRate());

        foreach ($composed['items'] as $composedItem) {
            if (! isset($composedItem['ref'])) {
                continue;
            }

            OrderItem::whereKey($composedItem['ref'])->update([
                'quantity' => $composedItem['quantity'],
                'base_price_at_sale' => $composedItem['base_price_at_sale'],
                'discount_amount_at_sale' => $composedItem['discount_amount_at_sale'],
                'final_price_at_sale' => $composedItem['final_price_at_sale'],
                'tax_amount_at_sale' => $composedItem['tax_amount_at_sale'],
            ]);
        }

        $order->update([
            'subtotal' => $composed['subtotal'],
            'iva_total' => $composed['iva_total'],
            'total' => $composed['total'],
        ]);

        return $composed;
    }

    /**
     * Rescales one raw line to a new quantity.
     *
     * The unit price comes from the stored line, not from the catalog: a price
     * change after the product was ordered must not rewrite what the guest was
     * quoted. The promotion discount is recomputed over the new gross because
     * fixed-amount and freebie promotions do not scale linearly with quantity.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function replaceLine(array $lines, string $itemId, int $newQuantity): array
    {
        foreach ($lines as $index => $line) {
            if (($line['ref'] ?? null) !== $itemId) {
                continue;
            }

            $previousQuantity = max(1, (int) $line['quantity']);
            $unitGross = (float) $line['line_gross'] / $previousQuantity;
            $newGross = round($unitGross * $newQuantity, 2);

            $promotion = $line['promotion_id']
                ? Promotion::withTrashed()->find($line['promotion_id'])
                : null;

            $lines[$index]['quantity'] = $newQuantity;
            $lines[$index]['line_gross'] = $newGross;
            $lines[$index]['item_discount'] = match (true) {
                $promotion !== null => $this->calculator->promotionDiscount($promotion, $newGross),
                // Promotion purged from the catalog: keep the discount the
                // guest was already granted, scaled to the new quantity, rather
                // than silently repricing the line upwards.
                (bool) $line['promotion_id'] => round((float) $line['item_discount'] / $previousQuantity * $newQuantity, 2),
                default => 0.0,
            };

            break;
        }

        return $lines;
    }

    /**
     * Drops a line from the raw set.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function dropLine(array $lines, string $itemId): array
    {
        return array_values(array_filter(
            $lines,
            fn (array $line) => ($line['ref'] ?? null) !== $itemId
        ));
    }

    /**
     * Gives units back to inventory and leaves the movement on the ledger.
     *
     * Sales only decrement `current_stock`, but every reversal in the system
     * writes a StockMovement so a warehouse count can be reconciled against an
     * explicit adjustment instead of an unexplained jump.
     */
    private function returnStock(?Product $product, int $quantity, OrderItem $orderItem, User $user): void
    {
        if (! $product || ! $product->track_stock || $quantity <= 0) {
            return;
        }

        $product->increment('current_stock', $quantity);

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'type' => 'adjustment',
            'quantity' => $quantity,
            'cost_price_at_movement' => $orderItem->base_price_at_sale ?? 0,
            'reason' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Limite de 1 promocion por ticket, evaluado sobre la cuenta completa.
     */
    private function assertPromotionLimit(iterable $existingItems, array $newItems): void
    {
        $promotionIds = collect($existingItems)->pluck('promotion_id')
            ->merge(collect($newItems)->pluck('promotion_id'))
            ->filter()
            ->unique();

        if ($promotionIds->count() > 1) {
            throw new TableConflictException(
                'ERR_POS_PROMOTION_LIMIT_EXCEEDED',
                'Solo se permite aplicar 1 promocion o cupon por cuenta de mesa.'
            );
        }
    }

    private function taxRate(): float
    {
        $setting = GlobalSetting::where('key', 'tax_rate')->first();

        return $setting ? (float) ($setting->value['rate'] ?? 0.16) : 0.16;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }

    private function presentSession(TableSession $session): array
    {
        $session->load([
            'table:id,name,capacity,zone,status',
            'user:id,name',
            'order.items.product:id,name,sku,sale_price',
            'order.items.promotion:id,name,type,value',
        ]);

        $order = $session->order;

        return [
            'id' => $session->id,
            'table' => $session->table,
            'order_id' => $session->order_id,
            'status' => $session->status,
            'guests' => $session->guests,
            'notes' => $session->notes,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'waiter' => $session->user ? ['id' => $session->user->id, 'name' => $session->user->name] : null,
            'subtotal' => (float) ($order?->subtotal ?? 0),
            'iva_total' => (float) ($order?->iva_total ?? 0),
            'total' => (float) ($order?->total ?? 0),
            'items' => $order ? $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name ?? 'Producto eliminado',
                'product_sku' => $item->product?->sku,
                'promotion' => $item->promotion ? ['id' => $item->promotion->id, 'name' => $item->promotion->name] : null,
                'quantity' => $item->quantity,
                'base_price_at_sale' => (float) $item->base_price_at_sale,
                'discount_amount_at_sale' => (float) $item->discount_amount_at_sale,
                'final_price_at_sale' => (float) $item->final_price_at_sale,
                'added_at' => $item->created_at?->toIso8601String(),
            ])->all() : [],
        ];
    }
}
