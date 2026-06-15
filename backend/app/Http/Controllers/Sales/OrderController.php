<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\TicketConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['items.product', 'items.promotion', 'ticketConfig'])
            ->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
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
        $order->load(['items.product', 'items.promotion', 'ticketConfig', 'cashRegister']);

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
                'payment_method' => $request->payment_method,
                'subtotal' => round($subtotal, 2),
                'iva_total' => $ivaTotal,
                'total' => $total,
                'custom_legend' => $request->custom_legend,
            ]);

            foreach ($itemsData as $itemData) {
                $order->items()->create($itemData);
            }

            $cashRegister->increment('expected_closing_balance', $total);

            return $order;
        });

        $order->load(['items.product', 'items.promotion', 'ticketConfig']);

        return response()->json([
            'status' => 'success',
            'data' => $order,
            'metadata' => ['message' => 'Orden procesada exitosamente.'],
        ], 201);
    }
}
