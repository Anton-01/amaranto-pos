<?php

namespace App\Http\Controllers\Sales;

use App\Builders\TicketBuilder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\GlobalSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\StockMovement;
use App\Models\TicketConfig;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\OrderCalculator;
use App\Services\PrinterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OrderController extends Controller
{
    public function __construct(
        private readonly TicketBuilder $ticketBuilder,
        private readonly PrinterService $printerService,
        private readonly OrderCalculator $calculator,
    ) {}
    public function index(Request $request): JsonResponse
    {
        // settled() deja fuera las cuentas de mesa abiertas: aun no son ventas.
        $query = Order::settled()
            ->with(['items.product', 'items.promotion', 'ticketConfig', 'paymentMethod', 'cashRegister.user', 'promotion', 'table', 'waiter:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('table_id')) {
            $query->where('table_id', $request->table_id);
        }
        if ($request->boolean('dine_in_only')) {
            $query->whereNotNull('table_id');
        }

        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->date_from, 'America/Mexico_City')->startOfDay();
            $query->where('created_at', '>=', $from);
        }
        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->date_to, 'America/Mexico_City')->endOfDay();
            $query->where('created_at', '<=', $to);
        }
        if ($request->filled('payment_method_id')) {
            $query->where('payment_method_id', $request->payment_method_id);
        }
        if ($request->filled('status') && in_array($request->status, ['completed', 'canceled'])) {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $query->whereHas('cashRegister', fn ($q) => $q->where('user_id', $request->user_id));
        }
        if ($request->filled('total_min')) {
            $query->where('total', '>=', $request->total_min);
        }
        if ($request->filled('total_max')) {
            $query->where('total', '<=', $request->total_max);
        }

        $orders = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'status' => 'success',
            'data' => $orders->items(),
            'metadata' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'items.product', 'items.promotion', 'ticketConfig', 'cashRegister.user',
            'paymentMethod', 'canceledByUser', 'promotion',
            'table', 'waiter:id,name,email', 'tableSession.user:id,name', 'tableSession.closedByUser:id,name',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $activeConfig = TicketConfig::where('is_active', true)->first();

        if (!$activeConfig) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TICKET_NO_ACTIVE_CONFIG',
                'message' => 'No hay una configuracion de ticket activa. Configure un diseno de ticket antes de procesar ventas.',
                'errors' => null,
                'metadata' => null,
            ], 422);
        }

        $user = $request->user();

        $cashRegister = CashRegister::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (!$cashRegister) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_POS_CASH_REGISTER_REQUIRED',
                'message' => 'Debes abrir una caja antes de procesar ventas. Realiza la apertura de turno desde el Punto de Venta.',
                'errors' => null,
                'metadata' => null,
            ], 422);
        }

        $order = DB::transaction(function () use ($request, $activeConfig, $user, $cashRegister) {

            $taxSetting = GlobalSetting::where('key', 'tax_rate')->first();
            $taxRate = $taxSetting ? (float) ($taxSetting->value['rate'] ?? 0.16) : 0.16;

            $lines = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $promotionId = $item['promotion_id'] ?? null;
                $promotion = $promotionId ? Promotion::findOrFail($promotionId) : null;
                $quantity = (int) $item['quantity'];

                $lines[] = $this->calculator->line($product, $promotion, $quantity);

                if ($product->track_stock) {
                    $product->decrement('current_stock', $quantity);
                }
            }

            $discountType = $request->input('discount_type', 'none');
            $discountValue = (float) $request->input('discount_value', 0);
            $globalPromotionId = $request->input('promotion_id');

            $composed = $this->calculator->compose($lines, $taxRate, $discountType, $discountValue);

            $order = Order::create([
                'cash_register_id' => $cashRegister->id,
                'ticket_config_id' => $activeConfig->id,
                'payment_method_id' => $request->payment_method_id,
                'promotion_id' => $globalPromotionId,
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

            foreach ($composed['items'] as $itemData) {
                $order->items()->create($itemData + ['created_at' => now()]);
            }

            $cashRegister->increment('expected_closing_balance', $composed['total']);

            return $order;
        });

        $order->load(['items.product', 'items.promotion', 'ticketConfig', 'paymentMethod', 'cashRegister.user', 'promotion']);

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
            'metadata' => ['message' => 'Orden procesada exitosamente.'],
        ], 201);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'admin_password' => 'required|string',
            'reason' => 'required|string|max:500',
        ]);

        if ($order->status === Order::STATUS_CANCELED) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_ORDER_ALREADY_CANCELED',
                'message' => 'Esta orden ya fue cancelada previamente.',
            ], 422);
        }

        // Cancelar aqui una cuenta de mesa dejaria la mesa ocupada para siempre:
        // la liberacion es responsabilidad del modulo de comedor.
        if ($order->status === Order::STATUS_OPEN) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_ORDER_STILL_OPEN',
                'message' => 'Esta cuenta pertenece a una mesa abierta. Cancelala desde el plano de mesas para liberar la mesa.',
            ], 422);
        }

        $authorizer = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'manager']))
            ->get()
            ->first(fn ($u) => Hash::check($request->admin_password, $u->password));

        if (!$authorizer) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_ORDER_CANCEL_UNAUTHORIZED',
                'message' => 'La contraseña de administrador es incorrecta.',
            ], 403);
        }

        DB::transaction(function () use ($order, $request, $authorizer) {
            $order->load('items');

            foreach ($order->items as $item) {
                if ($item->product_id) {
                    $product = Product::find($item->product_id);
                    if ($product && $product->track_stock) {
                        $product->increment('current_stock', $item->quantity);

                        StockMovement::create([
                            'product_id' => $product->id,
                            'user_id' => $authorizer->id,
                            'type' => 'adjustment',
                            'quantity' => $item->quantity,
                            'cost_price_at_movement' => $item->base_price_at_sale ?? 0,
                            'reason' => null,
                        ]);
                    }
                }
            }

            $order->update([
                'status' => 'canceled',
                'canceled_by' => $authorizer->id,
                'canceled_at' => now(),
                'cancellation_reason' => $request->reason,
            ]);

            AuditLog::create([
                'action' => 'order_canceled',
                'auditable_type' => 'Order',
                'auditable_id' => $order->id,
                'user_id' => $authorizer->id,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_total' => $order->total,
                    'reason' => $request->reason,
                    'authorizer_name' => $authorizer->name,
                    'authorizer_email' => $authorizer->email,
                    'items_count' => $order->items->count(),
                    'stock_reverted' => $order->items->filter(fn ($i) => $i->product_id && optional(Product::find($i->product_id))->track_stock)->count() > 0,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        $order->load(['items.product', 'paymentMethod', 'canceledByUser']);

        return response()->json([
            'status' => 'success',
            'data' => $order,
            'metadata' => ['message' => 'Orden cancelada exitosamente. El stock ha sido revertido.'],
        ]);
    }
}
