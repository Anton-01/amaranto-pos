<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $startOfMonth = now()->startOfMonth();
        $today = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        $monthlySales = DB::table('orders')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startOfMonth, $endOfDay])
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->value('total');

        $todayOrders = DB::table('orders')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$today, $endOfDay])
            ->count();

        $avgTicket = DB::table('orders')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startOfMonth, $endOfDay])
            ->selectRaw('COALESCE(AVG(total), 0) as avg_total')
            ->value('avg_total');

        $lowStock = DB::table('products')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->where('minimum_stock', '>', 0)
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'monthly_sales' => round((float) $monthlySales, 2),
                'today_orders' => $todayOrders,
                'avg_ticket' => round((float) $avgTicket, 2),
                'low_stock_alerts' => $lowStock,
            ],
        ]);
    }

    public function hourlyTrend(): JsonResponse
    {
        $today = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        $hourly = DB::table('orders')
            ->select(
                DB::raw("EXTRACT(HOUR FROM created_at)::int as hour"),
                DB::raw('COALESCE(SUM(total), 0) as total'),
                DB::raw('COUNT(*) as orders')
            )
            ->where('status', 'completed')
            ->whereBetween('created_at', [$today, $endOfDay])
            ->groupBy(DB::raw("EXTRACT(HOUR FROM created_at)::int"))
            ->orderBy('hour')
            ->get();

        $data = [];
        foreach (range(0, 23) as $h) {
            $found = $hourly->firstWhere('hour', $h);
            $data[] = [
                'hour' => sprintf('%02d:00', $h),
                'total' => $found ? round((float) $found->total, 2) : 0,
                'orders' => $found ? (int) $found->orders : 0,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function topProducts(): JsonResponse
    {
        $startOfMonth = now()->startOfMonth();
        $endOfDay = now()->endOfDay();

        $top = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->select(
                'order_items.product_id',
                DB::raw("COALESCE(products.name, 'Producto eliminado') as name"),
                DB::raw('SUM(order_items.quantity) as total_qty'),
                DB::raw('SUM(order_items.final_price_at_sale) as total_revenue')
            )
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$startOfMonth, $endOfDay])
            ->groupBy('order_items.product_id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $top,
        ]);
    }
}
