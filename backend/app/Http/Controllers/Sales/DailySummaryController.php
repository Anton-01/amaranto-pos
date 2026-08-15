<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DailySummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = Carbon::now()->startOfDay();
        $endOfDay = Carbon::now()->endOfDay();

        $sales = DB::table('orders')
            ->select(
                DB::raw('COALESCE(SUM(total), 0) as gross_income'),
                DB::raw('COALESCE(SUM(subtotal), 0) as net_income'),
                DB::raw('COALESCE(SUM(iva_total), 0) as total_tax'),
                DB::raw('COALESCE(SUM(discount_total), 0) as total_discounts'),
                DB::raw('COUNT(*) as order_count')
            )
            ->where('status', 'completed')
            ->whereBetween('created_at', [$today, $endOfDay])
            ->first();

        $byPayment = DB::table('orders')
            ->join('payment_methods', 'orders.payment_method_id', '=', 'payment_methods.id')
            ->select('payment_methods.slug', 'payment_methods.name', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(orders.total), 0) as total'))
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$today, $endOfDay])
            ->groupBy('payment_methods.slug', 'payment_methods.name')
            ->get()
            ->keyBy('slug');

        $pettyCash = (float) DB::table('petty_cash_transactions')
            ->whereBetween('created_at', [$today, $endOfDay])
            ->sum('amount');

        $productBreakdown = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                DB::raw("COALESCE(products.name, 'Producto eliminado') as product_name"),
                DB::raw('SUM(order_items.quantity) as quantity_sold'),
                DB::raw('SUM(order_items.final_price_at_sale) as total_revenue')
            )
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$today, $endOfDay])
            ->groupBy('products.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'gross_income' => round((float) $sales->gross_income, 2),
                'net_income' => round((float) $sales->net_income, 2),
                'total_tax' => round((float) $sales->total_tax, 2),
                'total_discounts' => round((float) $sales->total_discounts, 2),
                'order_count' => (int) $sales->order_count,
                'by_payment' => $byPayment->map(fn ($row) => [
                    'name' => $row->name,
                    'count' => (int) $row->count,
                    'total' => round((float) $row->total, 2),
                ]),
                'petty_cash_total' => round($pettyCash, 2),
                'product_breakdown' => $productBreakdown->map(fn ($row) => [
                    'product_name' => $row->product_name,
                    'quantity_sold' => (int) $row->quantity_sold,
                    'total_revenue' => round((float) $row->total_revenue, 2),
                ])->values(),
            ],
        ]);
    }
}
