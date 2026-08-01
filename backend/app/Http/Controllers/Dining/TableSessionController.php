<?php

namespace App\Http\Controllers\Dining;

use App\Builders\TicketBuilder;
use App\Exceptions\TableConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TableSession\AddTableItemsRequest;
use App\Http\Requests\TableSession\CloseTableRequest;
use App\Http\Requests\TableSession\OpenTableRequest;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\GlobalSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TicketConfig;
use App\Services\OrderCalculator;
use App\Services\PrinterService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
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
