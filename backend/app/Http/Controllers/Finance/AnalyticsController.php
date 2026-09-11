<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\GlobalSetting;
use App\Services\CurrentDayBreakdownService;
use App\Services\FinanceExportService;
use App\Support\Finance\FinanceFilters;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytical surface of the financial panel.
 *
 * Every endpoint here resolves its criteria through App\Support\Finance\
 * FinanceFilters, so the payment-method chart, the 70/30 summary, the trend and
 * the exported workbook all describe the same slice of the business. That
 * shared object is the whole reason the four cannot drift apart.
 */
class AnalyticsController extends Controller
{
    /**
     * GET /api/analytics/current-day
     *
     * Money flow of the day in progress, broken down register by register.
     *
     * Deliberately unfiltered and uncached: this is the panel's opening view
     * and it answers "where is the money RIGHT NOW". A cached answer here would
     * show a cashier a total that predates the sale they just rang up, and the
     * next thing they would do is stop trusting the screen.
     */
    public function currentDay(Request $request, CurrentDayBreakdownService $breakdown): JsonResponse
    {
        // An explicit date lets the panel look back at a previous business day
        // through the exact same view; absent it, today.
        $day = $request->filled('date')
            ? Carbon::parse($request->string('date')->toString())
            : Carbon::now();

        return response()->json([
            'status' => 'success',
            'data' => $breakdown->build($day),
        ]);
    }

    /** GET /api/analytics/catalogs — options for the advanced filter block. */
    public function catalogs(): JsonResponse
    {
        $paymentMethods = DB::table('payment_methods')
            ->select('id', 'name', 'slug')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        /*
         * Only operators who have actually held a drawer. Listing every user in
         * the system would offer filters that can only ever return zero rows —
         * a filter that cannot match anything is noise, not a feature.
         */
        $operators = DB::table('users')
            ->join('cash_registers', 'cash_registers.user_id', '=', 'users.id')
            ->select('users.id', 'users.name')
            ->distinct()
            ->orderBy('users.name')
            ->get();

        // Recent registers, labelled by operator and date: a raw UUID is not a
        // thing a human can pick from a dropdown.
        $registers = DB::table('cash_registers')
            ->leftJoin('users', 'cash_registers.user_id', '=', 'users.id')
            ->select(
                'cash_registers.id',
                'cash_registers.opened_at',
                'cash_registers.closed_at',
                'users.name as operator_name',
            )
            ->orderByDesc('cash_registers.opened_at')
            ->limit(60)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'payment_methods' => $paymentMethods,
                'operators' => $operators,
                'cash_registers' => $registers,
            ],
        ]);
    }

    public function salesByPaymentMethod(Request $request): JsonResponse
    {
        $filters = FinanceFilters::fromRequest($request, Carbon::now()->subDays(6)->startOfDay());

        $query = DB::table('orders')
            ->join('payment_methods', 'orders.payment_method_id', '=', 'payment_methods.id')
            ->select(
                DB::raw(FinanceFilters::localDateExpression('orders.created_at').' as date'),
                'payment_methods.slug as payment_slug',
                'payment_methods.name as payment_name',
                DB::raw('SUM(orders.total) as total_income'),
                DB::raw('COUNT(*) as order_count'),
            )
            ->where('orders.status', 'completed')
            ->groupBy(
                DB::raw(FinanceFilters::localDateExpression('orders.created_at')),
                'payment_methods.slug',
                'payment_methods.name',
            )
            ->orderBy('date');

        $filters->applyToOrders($query);

        $grouped = [];

        foreach ($query->get() as $row) {
            $date = $row->date;

            $grouped[$date] ??= ['date' => $date, 'methods' => [], 'total' => 0, 'orders' => 0];

            $grouped[$date]['methods'][$row->payment_slug] = [
                'name' => $row->payment_name,
                'total' => round((float) $row->total_income, 2),
            ];
            // The chart stacks by slug, so each method is also flattened onto
            // the row: Recharts reads `dataKey="efectivo"`, not a nested map.
            $grouped[$date][$row->payment_slug] = round((float) $row->total_income, 2);
            $grouped[$date]['total'] = round($grouped[$date]['total'] + (float) $row->total_income, 2);
            $grouped[$date]['orders'] += (int) $row->order_count;
        }

        return response()->json([
            'status' => 'success',
            'data' => array_values($grouped),
            'metadata' => ['filters' => $filters->toMetadata()],
        ]);
    }

    public function financialSummary(Request $request): JsonResponse
    {
        $filters = FinanceFilters::fromRequest($request, Carbon::now()->startOfMonth());

        $investmentPct = (int) $this->setting('investment_split', 'investment_pct', 70);
        $profitPct = (int) $this->setting('investment_split', 'profit_pct', 30);

        $sales = $filters->applyToOrders(
            DB::table('orders')
                ->select(
                    DB::raw('COALESCE(SUM(total), 0) as total_income'),
                    DB::raw('COALESCE(SUM(discount_total), 0) as total_discounts'),
                    DB::raw('COUNT(*) as order_count'),
                )
                ->where('status', 'completed')
        )->first();

        /*
         * ONE INCOME FIGURE. The panel used to carry the charged total and the
         * net subtotal side by side and segment the 70/30 on the latter, which
         * left the fund and the profit describing a smaller business than the
         * headline figure right next to them. Both the base and every derived
         * amount are now the total charged.
         */
        $totalIncome = round((float) $sales->total_income, 2);
        $investmentFund = round($totalIncome * ($investmentPct / 100), 2);

        $pettyCash = (float) $filters
            ->applyToDeductions(DB::table('petty_cash_transactions'), 'petty_cash_transactions')
            ->sum('amount');

        $stockPurchases = (float) $filters
            ->applyToDeductions(DB::table('stock_movements'), 'stock_movements')
            ->where('type', 'purchase_input')
            ->selectRaw('COALESCE(SUM(cost_price_at_movement * quantity), 0) as total')
            ->value('total');

        $merma = (float) $filters
            ->applyToDeductions(DB::table('stock_movements'), 'stock_movements')
            ->where('type', 'merma_output')
            ->selectRaw('COALESCE(SUM(cost_price_at_movement * quantity), 0) as total')
            ->value('total');

        $totalDeductions = round($pettyCash + $stockPurchases, 2);

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => ['from' => $filters->from->toDateString(), 'to' => $filters->to->toDateString()],
                'split' => ['investment_pct' => $investmentPct, 'profit_pct' => $profitPct],
                'total_income' => $totalIncome,
                'total_discounts' => round((float) $sales->total_discounts, 2),
                'order_count' => (int) $sales->order_count,
                'investment_fund' => $investmentFund,
                'net_profit' => round($totalIncome * ($profitPct / 100), 2),
                'deductions' => [
                    'petty_cash' => round($pettyCash, 2),
                    'stock_purchases' => round($stockPurchases, 2),
                    'merma_losses' => round($merma, 2),
                    'total' => $totalDeductions,
                ],
                'investment_remaining' => round($investmentFund - $totalDeductions, 2),
            ],
            /*
             * `deductions_comparable` is the flag the UI needs to decide whether
             * to show the remainder or a warning. With a payment-method or
             * register filter active the income is a slice and the deductions
             * are the whole business, so their difference means nothing — and
             * a financial panel that displays a meaningless number without
             * saying so is worse than one that displays nothing.
             */
            'metadata' => ['filters' => $filters->toMetadata()],
        ]);
    }

    public function dailyTrend(Request $request): JsonResponse
    {
        $filters = FinanceFilters::fromRequest($request, Carbon::now()->subDays(29)->startOfDay());

        $query = DB::table('orders')
            ->select(
                DB::raw(FinanceFilters::localDateExpression('created_at').' as date'),
                DB::raw('SUM(total) as total_income'),
                DB::raw('COUNT(*) as order_count'),
            )
            ->where('status', 'completed')
            ->groupBy(DB::raw(FinanceFilters::localDateExpression('created_at')))
            ->orderBy('date');

        $filters->applyToOrders($query);

        return response()->json([
            'status' => 'success',
            'data' => $query->get(),
            'metadata' => ['filters' => $filters->toMetadata()],
        ]);
    }

    /**
     * GET /api/analytics/export — the financial audit workbook.
     *
     * STRICTLY .XLSX. The response is produced by PhpSpreadsheet's Xlsx writer
     * and there is no CSV branch: a comma-separated file cannot carry the four
     * sheets, the peso number formats or the live SUM() formulas that make this
     * file auditable rather than merely readable.
     *
     * Streamed rather than returned as a string so a year of orders does not
     * sit in memory twice — once as the workbook, once as the response body.
     */
    public function export(Request $request, FinanceExportService $exporter): StreamedResponse
    {
        $filters = FinanceFilters::fromRequest($request, Carbon::now()->startOfMonth());

        $spreadsheet = $exporter->build($filters);
        $writer = $exporter->writer($spreadsheet);

        return response()->streamDownload(
            function () use ($writer, $spreadsheet) {
                $writer->save('php://output');
                // PhpSpreadsheet holds every cell in memory; on a wide export
                // that is tens of megabytes the worker would otherwise keep
                // until the request tears down.
                $spreadsheet->disconnectWorksheets();
            },
            $exporter->filename($filters),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    private function setting(string $key, string $field, float|int $default): float|int
    {
        $setting = GlobalSetting::where('key', $key)->first();

        return $setting ? ($setting->value[$field] ?? $default) : $default;
    }
}
