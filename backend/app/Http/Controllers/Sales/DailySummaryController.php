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

        return response()->json([
            'status' => 'success',
            'data' => [
                'gross_income' => round((float) $sales->gross_income, 2),
                'net_income' => round((float) $sales->net_income, 2),
                'total_tax' => round((float) $sales->total_tax, 2),
                'order_count' => (int) $sales->order_count,
                'by_payment' => $byPayment->map(fn ($row) => [
                    'name' => $row->name,
                    'count' => (int) $row->count,
                    'total' => round((float) $row->total, 2),
                ]),
                'petty_cash_total' => round($pettyCash, 2),
            ],
        ]);
    }
}
