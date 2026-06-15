<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\GlobalSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function salesByPaymentMethod(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->subDays(6)->startOfDay()->toDateTimeString());
        $dateTo = $request->input('date_to', now()->endOfDay()->toDateTimeString());

        $daily = DB::table('orders')
            ->select(
                DB::raw("DATE(created_at) as date"),
                'payment_method',
                DB::raw('SUM(subtotal) as total_net'),
                DB::raw('SUM(total) as total_gross'),
                DB::raw('COUNT(*) as order_count')
            )
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->groupBy(DB::raw('DATE(created_at)'), 'payment_method')
            ->orderBy('date')
            ->get();

        $grouped = [];
        foreach ($daily as $row) {
            $date = $row->date;
            if (!isset($grouped[$date])) {
                $grouped[$date] = [
                    'date' => $date,
                    'efectivo' => 0,
                    'tarjeta' => 0,
                    'transferencia' => 0,
                    'total' => 0,
                    'orders' => 0,
                ];
            }
            $grouped[$date][$row->payment_method] = round((float) $row->total_net, 2);
            $grouped[$date]['total'] += round((float) $row->total_net, 2);
            $grouped[$date]['orders'] += (int) $row->order_count;
        }

        return response()->json([
            'status' => 'success',
            'data' => array_values($grouped),
            'metadata' => ['period' => ['from' => $dateFrom, 'to' => $dateTo]],
        ]);
    }

    public function financialSummary(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->endOfDay()->toDateTimeString());

        $taxSetting = GlobalSetting::where('key', 'tax_rate')->first();
        $taxRate = $taxSetting ? ($taxSetting->value['rate'] ?? 0.16) : 0.16;

        $splitSetting = GlobalSetting::where('key', 'investment_split')->first();
        $investmentPct = $splitSetting ? ($splitSetting->value['investment_pct'] ?? 70) : 70;
        $profitPct = $splitSetting ? ($splitSetting->value['profit_pct'] ?? 30) : 30;

        $salesAgg = DB::table('orders')
            ->select(
                DB::raw('COALESCE(SUM(subtotal), 0) as net_income'),
                DB::raw('COALESCE(SUM(iva_total), 0) as total_tax'),
                DB::raw('COALESCE(SUM(total), 0) as gross_income'),
                DB::raw('COUNT(*) as order_count')
            )
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->first();

        $netIncome = round((float) $salesAgg->net_income, 2);
        $grossIncome = round((float) $salesAgg->gross_income, 2);
        $totalTax = round((float) $salesAgg->total_tax, 2);

        $investmentFund = round($netIncome * ($investmentPct / 100), 2);
        $netProfit = round($netIncome * ($profitPct / 100), 2);

        $pettyCashTotal = (float) DB::table('petty_cash_transactions')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('amount');

        $stockPurchaseCost = (float) DB::table('stock_movements')
            ->where('type', 'purchase_input')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(SUM(cost_price_at_movement * quantity), 0) as total')
            ->value('total');

        $mermaCost = (float) DB::table('stock_movements')
            ->where('type', 'merma_output')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(SUM(cost_price_at_movement * quantity), 0) as total')
            ->value('total');

        $totalDeductions = round($pettyCashTotal + $stockPurchaseCost, 2);
        $investmentRemaining = round($investmentFund - $totalDeductions, 2);

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => ['from' => $dateFrom, 'to' => $dateTo],
                'tax_rate' => $taxRate,
                'split' => ['investment_pct' => $investmentPct, 'profit_pct' => $profitPct],
                'gross_income' => $grossIncome,
                'total_tax' => $totalTax,
                'net_income' => $netIncome,
                'order_count' => (int) $salesAgg->order_count,
                'investment_fund' => $investmentFund,
                'net_profit' => $netProfit,
                'deductions' => [
                    'petty_cash' => round($pettyCashTotal, 2),
                    'stock_purchases' => round($stockPurchaseCost, 2),
                    'merma_losses' => round($mermaCost, 2),
                    'total' => $totalDeductions,
                ],
                'investment_remaining' => $investmentRemaining,
            ],
        ]);
    }

    public function dailyTrend(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->subDays(29)->startOfDay()->toDateTimeString());
        $dateTo = $request->input('date_to', now()->endOfDay()->toDateTimeString());

        $daily = DB::table('orders')
            ->select(
                DB::raw("DATE(created_at) as date"),
                DB::raw('SUM(subtotal) as net_income'),
                DB::raw('SUM(total) as gross_income'),
                DB::raw('COUNT(*) as order_count')
            )
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $daily,
            'metadata' => ['period' => ['from' => $dateFrom, 'to' => $dateTo]],
        ]);
    }
}
