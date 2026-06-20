<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesExportController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $query = Order::with(['paymentMethod', 'cashRegister.user'])
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
        if ($request->filled('status')) {
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

        $orders = $query->get();

        $dateFromLabel = $request->input('date_from', 'Inicio');
        $dateToLabel = $request->input('date_to', 'Hoy');

        $filename = 'ventas_cronos_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($orders, $dateFromLabel, $dateToLabel) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['CRONOS POS - Reporte de Ventas']);
            fputcsv($handle, ['Fecha de generacion: ' . now()->format('d/m/Y H:i:s')]);
            fputcsv($handle, ['Periodo: ' . $dateFromLabel . ' a ' . $dateToLabel]);
            fputcsv($handle, ['Total de registros: ' . $orders->count()]);
            fputcsv($handle, []);

            fputcsv($handle, [
                'ID Ticket',
                'Fecha/Hora',
                'Vendedor',
                'Metodo de Pago',
                'Subtotal',
                'IVA',
                'Total',
                'Estatus',
            ]);

            foreach ($orders as $order) {
                fputcsv($handle, [
                    strtoupper(substr($order->id, 0, 8)),
                    $order->created_at?->format('d/m/Y H:i'),
                    $order->cashRegister?->user?->name ?? 'N/A',
                    $order->paymentMethod?->name ?? 'N/A',
                    number_format((float) $order->subtotal, 2, '.', ''),
                    number_format((float) $order->iva_total, 2, '.', ''),
                    number_format((float) $order->total, 2, '.', ''),
                    $order->status === 'completed' ? 'Completado' : 'Cancelado',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
