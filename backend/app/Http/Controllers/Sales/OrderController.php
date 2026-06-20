<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
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

        $order = DB::transaction(function () use ($request, $activeConfig, $user) {
            $cashRegister = CashRegister::where('user_id', $user->id)
                ->whereNull('closed_at')
                ->first();

            if (!$cashRegister) {
                $cashRegister = CashRegister::create([
                    'user_id' => $user->id,
                    'opened_at' => now(),
                    'opening_balance' => 0,
                ]);
            }

            $subtotal = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $promotionId = $item['promotion_id'] ?? null;

                $basePrice = (float) $product->sale_price;
                $quantity = (int) $item['quantity'];
                $lineTotal = $basePrice * $quantity;
                $discount = 0;

                if ($promotionId) {
                    $promotion = Promotion::findOrFail($promotionId);

                    if ($promotion->type === 'percentage') {
                        $discount = $lineTotal * ((float) $promotion->value / 100);
                    } elseif ($promotion->type === 'fixed_amount') {
                        $discount = min((float) $promotion->value, $lineTotal);
                    } elseif ($promotion->type === 'freebie_100') {
                        $discount = $lineTotal;
                    }
                }

                $finalLineTotal = $lineTotal - $discount;
                $tax = $finalLineTotal * 0.16;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'promotion_id' => $promotionId,
                    'quantity' => $quantity,
                    'base_price_at_sale' => $basePrice,
                    'discount_amount_at_sale' => round($discount, 2),
                    'final_price_at_sale' => round($finalLineTotal, 2),
                    'tax_amount_at_sale' => round($tax, 2),
                ];

                $subtotal += $finalLineTotal;

                $product->decrement('current_stock', $quantity);
            }

            $ivaTotal = round($subtotal * 0.16, 2);
            $total = round($subtotal + $ivaTotal, 2);

            $order = Order::create([
                'cash_register_id' => $cashRegister->id,
                'ticket_config_id' => $activeConfig->id,
                'payment_method_id' => $request->payment_method_id,
                'subtotal' => round($subtotal, 2),
                'iva_total' => $ivaTotal,
                'total' => $total,
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

        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();

        if (!$admin || !Hash::check($request->admin_password, $admin->password)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_ORDER_CANCEL_UNAUTHORIZED',
                'message' => 'La contraseña de administrador es incorrecta.',
            ], 403);
        }

        DB::transaction(function () use ($order, $request, $admin) {
            $order->load('items');

            foreach ($order->items as $item) {
                if ($item->product_id) {
                    Product::where('id', $item->product_id)
                        ->increment('current_stock', $item->quantity);
                }
            }

            $order->update([
                'status' => 'canceled',
                'canceled_by' => $admin->id,
                'canceled_at' => now(),
                'cancellation_reason' => $request->reason,
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
