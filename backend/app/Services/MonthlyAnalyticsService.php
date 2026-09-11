<?php

namespace App\Services;

use App\Models\CacheConfiguration;
use App\Support\ModuleCache;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregations behind the Dashboard's Financial Analytics modal.
 *
 * WHY A SERVICE AND NOT A CONTROLLER METHOD. The same month is read twice by
 * two different endpoints — once as JSON for the charts, once as the .xlsx
 * workbook the "Exportar Reporte" button downloads — and the export exists to
 * hand somebody the numbers they are looking at. Two copies of these seven
 * aggregations would be two chances for the file and the screen to disagree,
 * which is the one thing a financial report cannot do.
 */
class MonthlyAnalyticsService
{
    /**
     * Month payload, memoized as a unit under the month it describes.
     *
     * The modal fires seven aggregations over two months in a single request,
     * which makes it the heaviest read of the whole application. Browsing back
     * through history is served from Redis and only the running month is ever
     * rebuilt — and it rebuilds the moment a sale is registered, because
     * Order::booted() purges this module.
     *
     * The refresh window is not hardcoded: ModuleCache resolves it from the
     * `monthly_analytics` row of `cache_configurations`.
     *
     * @return array<string, mixed>
     */
    public function forMonth(Carbon $month): array
    {
        return ModuleCache::remember(
            CacheConfiguration::MODULE_MONTHLY_ANALYTICS,
            $month->format('Y-m'),
            fn (): array => $this->buildPayload($month)
        );
    }

    /**
     * Assembles the whole analytics payload for one month.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(Carbon $month): array
    {
        $monthEnd = $month->copy()->endOfMonth();
        $previousMonth = $month->copy()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousMonth->copy()->endOfMonth();

        $current = $this->totals($month, $monthEnd);
        $previous = $this->totals($previousMonth, $previousEnd);

        return [
            'month' => $month->format('Y-m'),
            'month_label' => ucfirst($month->locale('es')->translatedFormat('F Y')),
            'totals' => $current,
            'previous' => $previous + ['month' => $previousMonth->format('Y-m')],
            'comparison' => [
                'sales_delta_pct' => $this->deltaPct($previous['total_sales'], $current['total_sales']),
                'orders_delta_pct' => $this->deltaPct($previous['order_count'], $current['order_count']),
                'avg_ticket_delta_pct' => $this->deltaPct($previous['avg_ticket'], $current['avg_ticket']),
            ],
            'by_payment_method' => $this->byPaymentMethod($month, $monthEnd),
            'top_products' => $this->topProducts($month, $monthEnd),
            'peak_hours' => $this->peakHours($month, $monthEnd),
            'daily_trend' => $this->dailyTrend($month, $monthEnd),
        ];
    }

    /** @return array{total_sales: float, discount_total: float, order_count: int, avg_ticket: float} */
    private function totals(Carbon $from, Carbon $to): array
    {
        $row = DB::table('orders')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(total), 0) as total_sales,
                COALESCE(SUM(discount_total), 0) as discount_total,
                COUNT(*) as order_count,
                COALESCE(AVG(total), 0) as avg_ticket
            ')
            ->first();

        return [
            'total_sales' => round((float) $row->total_sales, 2),
            'discount_total' => round((float) $row->discount_total, 2),
            'order_count' => (int) $row->order_count,
            'avg_ticket' => round((float) $row->avg_ticket, 2),
        ];
    }

    private function byPaymentMethod(Carbon $from, Carbon $to): array
    {
        return DB::table('orders')
            ->join('payment_methods', 'orders.payment_method_id', '=', 'payment_methods.id')
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.slug')
            ->selectRaw('payment_methods.name, payment_methods.slug, COUNT(*) as order_count, COALESCE(SUM(orders.total), 0) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'slug' => $row->slug,
                'order_count' => (int) $row->order_count,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    private function topProducts(Carbon $from, Carbon $to): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('order_items.product_id', 'products.name')
            ->selectRaw("COALESCE(products.name, 'Producto eliminado') as name, SUM(order_items.quantity) as quantity_sold, SUM(order_items.final_price_at_sale) as revenue")
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'quantity_sold' => (int) $row->quantity_sold,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /** Ventas agregadas por hora local del dia, para localizar horas pico. */
    private function peakHours(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('orders')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw("EXTRACT(HOUR FROM created_at AT TIME ZONE ".Timezone::sqlLiteral().")::int"))
            ->selectRaw("EXTRACT(HOUR FROM created_at AT TIME ZONE ".Timezone::sqlLiteral().")::int as hour, COUNT(*) as orders, COALESCE(SUM(total), 0) as total")
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        return collect(range(0, 23))->map(fn ($h) => [
            'hour' => sprintf('%02d:00', $h),
            'orders' => (int) ($rows[$h]->orders ?? 0),
            'total' => round((float) ($rows[$h]->total ?? 0), 2),
        ])->all();
    }

    private function dailyTrend(Carbon $from, Carbon $to): array
    {
        return DB::table('orders')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw("DATE(created_at AT TIME ZONE ".Timezone::sqlLiteral().")"))
            ->selectRaw("DATE(created_at AT TIME ZONE ".Timezone::sqlLiteral().") as day, COUNT(*) as orders, COALESCE(SUM(total), 0) as total")
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => $row->day,
                'orders' => (int) $row->orders,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    private function deltaPct(float|int $previous, float|int $current): ?float
    {
        if ((float) $previous === 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
