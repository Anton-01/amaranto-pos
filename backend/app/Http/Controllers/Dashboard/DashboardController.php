<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CacheConfiguration;
use App\Support\ModuleCache;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregations behind the executive dashboard.
 *
 * Payload discipline — none of these endpoints returns a model. They project
 * aggregates through explicit `selectRaw()` / `select()` lists, so the JSON
 * carries exactly the four KPIs, the 24 hourly buckets and the five product
 * rows the React view renders. There is no implicit `SELECT *` anywhere on
 * this path, which keeps timestamps, foreign keys and cost prices — data the
 * dashboard never displays — out of the response entirely.
 *
 * Caching — every query is wrapped in ModuleCache, whose TTL comes from the
 * `cache_configurations` row of the module. The cache keys are anchored to the
 * business day (or month) being rendered, so a date rollover lands on a fresh
 * entry instead of replaying yesterday's figures, and any write to `orders` or
 * `products` purges these payloads immediately (see the models' `booted()`).
 */
class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $today = Carbon::now()->startOfDay();

        $data = ModuleCache::remember(
            CacheConfiguration::MODULE_DASHBOARD_STATS,
            $today->toDateString(),
            function () use ($today): array {
                $startOfMonth = Carbon::now()->startOfMonth();
                $endOfDay = Carbon::now()->endOfDay();

                // One scan of the month instead of two: the running total and
                // the average come from the same aggregate row.
                $monthly = DB::table('orders')
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$startOfMonth, $endOfDay])
                    ->selectRaw('COALESCE(SUM(total), 0) as total, COALESCE(AVG(total), 0) as avg_total')
                    ->first();

                $todayOrders = DB::table('orders')
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$today, $endOfDay])
                    ->count();

                $lowStock = DB::table('products')
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->whereColumn('current_stock', '<=', 'minimum_stock')
                    ->where('minimum_stock', '>', 0)
                    ->count();

                return [
                    'monthly_sales' => round((float) $monthly->total, 2),
                    'today_orders' => $todayOrders,
                    'avg_ticket' => round((float) $monthly->avg_total, 2),
                    'low_stock_alerts' => $lowStock,
                ];
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function hourlyTrend(): JsonResponse
    {
        $today = Carbon::now()->startOfDay();

        $data = ModuleCache::remember(
            CacheConfiguration::MODULE_DASHBOARD_HOURLY,
            $today->toDateString(),
            function () use ($today): array {
                $endOfDay = Carbon::now()->endOfDay();

                $hourly = DB::table('orders')
                    ->select(
                        DB::raw("EXTRACT(HOUR FROM created_at AT TIME ZONE " . Timezone::sqlLiteral() . ")::int as hour"),
                        DB::raw('COALESCE(SUM(total), 0) as total'),
                        DB::raw('COUNT(*) as orders')
                    )
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$today, $endOfDay])
                    ->groupBy(DB::raw("EXTRACT(HOUR FROM created_at AT TIME ZONE " . Timezone::sqlLiteral() . ")::int"))
                    ->orderBy('hour')
                    ->get();

                // The chart needs all 24 buckets; PostgreSQL only returns the
                // hours that actually sold, so the gaps are filled here.
                $data = [];
                foreach (range(0, 23) as $h) {
                    $found = $hourly->firstWhere('hour', $h);
                    $data[] = [
                        'hour' => sprintf('%02d:00', $h),
                        'total' => $found ? round((float) $found->total, 2) : 0,
                        'orders' => $found ? (int) $found->orders : 0,
                    ];
                }

                return $data;
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function topProducts(): JsonResponse
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $data = ModuleCache::remember(
            CacheConfiguration::MODULE_DASHBOARD_TOP_PRODUCTS,
            $startOfMonth->format('Y-m'),
            function () use ($startOfMonth): array {
                $endOfDay = Carbon::now()->endOfDay();

                return DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
                    ->select(
                        'order_items.product_id',
                        // The join reaches `products` only for the name: the
                        // pie chart must never receive cost prices or stock.
                        DB::raw("COALESCE(products.name, 'Producto eliminado') as name"),
                        DB::raw('SUM(order_items.quantity) as total_qty'),
                        DB::raw('SUM(order_items.final_price_at_sale) as total_revenue')
                    )
                    ->where('orders.status', 'completed')
                    ->whereBetween('orders.created_at', [$startOfMonth, $endOfDay])
                    ->groupBy('order_items.product_id', 'products.name')
                    ->orderByDesc('total_qty')
                    ->limit(5)
                    ->get()
                    ->map(fn ($row): array => [
                        'product_id' => $row->product_id,
                        'name' => $row->name,
                        'total_qty' => (int) $row->total_qty,
                        'total_revenue' => round((float) $row->total_revenue, 2),
                    ])
                    ->all();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }
}
