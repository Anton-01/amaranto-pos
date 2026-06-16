<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DailySummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        $sales = DB::table('orders')
            ->select(
                DB::raw('COALESCE(SUM(total), 0) as gross_income'),
                DB::raw('COALESCE(SUM(subtotal), 0) as net_income'),
                DB::raw('COALESCE(SUM(iva_total), 0) as total_tax'),
                DB::raw('COUNT(*) as order_count')
            )
            ->whereBetween('created_at', [$today, $endOfDay])
            ->first();

        $byPayment = DB::table('orders')
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total), 0) as total'))
            ->whereBetween('created_at', [$today, $endOfDay])
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $pettyCash = (float) DB::table('petty_cash_transactions')
            ->whereBetween('created_at', [$today, $endOfDay])
            ->sum('amount');

        return response()->json([
            'status' => 'success',
            'data' => [
                'gross_income' => round((float) $sales->gross_income, 2),
                'net_income' => round((float) $sales->net_income, 2),
                'total_tax' => round((float) $sales->total_tax, 2),
                'order_count' => (int) $sales->order_count,
                'by_payment' => [
                    'efectivo' => round((float) ($byPayment['efectivo']->total ?? 0), 2),
                    'tarjeta' => round((float) ($byPayment['tarjeta']->total ?? 0), 2),
                    'transferencia' => round((float) ($byPayment['transferencia']->total ?? 0), 2),
                ],
                'petty_cash_total' => round($pettyCash, 2),
            ],
        ]);
    }
}
