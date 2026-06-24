<?php

namespace App\Http\Controllers\Sales;

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['items.product', 'items.promotion', 'ticketConfig', 'paymentMethod', 'cashRegister.user'])
            ->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
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
        $order->load(['items.product', 'items.promotion', 'ticketConfig', 'cashRegister.user', 'paymentMethod', 'canceledByUser']);

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

            $totalGross = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $promotionId = $item['promotion_id'] ?? null;

                $publicPrice = (float) $product->sale_price;
                $quantity = (int) $item['quantity'];
                $lineGross = $publicPrice * $quantity;
                $discount = 0;

                if ($promotionId) {
                    $promotion = Promotion::findOrFail($promotionId);

                    if ($promotion->type === 'percentage') {
                        $discount = $lineGross * ((float) $promotion->value / 100);
                    } elseif ($promotion->type === 'fixed_amount') {
                        $discount = min((float) $promotion->value, $lineGross);
                    } elseif ($promotion->type === 'freebie_100') {
                        $discount = $lineGross;
                    }
                }

                $finalLineTotal = $lineGross - $discount;
                $netPrice = $finalLineTotal / (1 + $taxRate);
                $tax = $finalLineTotal - $netPrice;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'promotion_id' => $promotionId,
                    'quantity' => $quantity,
                    'base_price_at_sale' => round($netPrice / $quantity, 2),
                    'discount_amount_at_sale' => round($discount, 2),
                    'final_price_at_sale' => round($finalLineTotal, 2),
                    'tax_amount_at_sale' => round($tax, 2),
                ];

                $totalGross += $finalLineTotal;

                if ($product->track_stock) {
                    $product->decrement('current_stock', $quantity);
                }
            }

            $subtotal = round($totalGross / (1 + $taxRate), 2);
            $ivaTotal = round($totalGross - $subtotal, 2);
            $total = round($totalGross, 2);

            $order = Order::create([
                'cash_register_id' => $cashRegister->id,
                'ticket_config_id' => $activeConfig->id,
                'payment_method_id' => $request->payment_method_id,
                'subtotal' => $subtotal,
                'iva_total' => $ivaTotal,
                'total' => $total,
                'amount_received' => $request->amount_received,
                'amount_change' => $request->amount_change,
                'custom_legend' => $request->custom_legend,
                'status' => 'completed',
            ]);

            foreach ($itemsData as $itemData) {
                $order->items()->create($itemData);
            }

            $cashRegister->increment('expected_closing_balance', $total);

            return $order;
        });

        $order->load(['items.product', 'items.promotion', 'ticketConfig', 'paymentMethod']);

        return response()->json([
            'status' => 'success',
            'data' => $order,
            'metadata' => ['message' => 'Orden procesada exitosamente.'],
        ], 201);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'admin_password' => 'required|string',
            'reason' => 'required|string|max:500',
        ]);

        if ($order->status === 'canceled') {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_ORDER_ALREADY_CANCELED',
                'message' => 'Esta orden ya fue cancelada previamente.',
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
