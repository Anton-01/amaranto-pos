<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\GlobalSetting;
use App\Models\Order;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

        $fiscal = GlobalSetting::where('key', 'fiscal_data')->first();
        $branchName = $fiscal?->value['business_name'] ?? 'Sucursal Principal';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Historial de Ventas');

        $headerStyle = [
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];

        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'CRONOS POS - HISTORIAL DE TRANSACCIONES');
        $sheet->getStyle('A1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(36);

        $subHeaderStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '475569']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', 'Sucursal: ' . $branchName);
        $sheet->getStyle('A2')->applyFromArray($subHeaderStyle);

        $sheet->mergeCells('A3:H3');
        $sheet->setCellValue('A3', 'Periodo: ' . $dateFromLabel . ' a ' . $dateToLabel . '  |  Generado: ' . now()->format('d/m/Y H:i:s') . '  |  Registros: ' . $orders->count());
        $sheet->getStyle('A3')->applyFromArray($subHeaderStyle);

        $columnHeaders = ['ID Ticket', 'Fecha/Hora', 'Vendedor', 'Metodo de Pago', 'Subtotal', 'IVA', 'Total', 'Estatus'];
        $colHeaderStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
        ];

        $row = 5;
        foreach ($columnHeaders as $col => $header) {
            $sheet->setCellValue([$col + 1, $row], $header);
        }
        $sheet->getStyle("A{$row}:H{$row}")->applyFromArray($colHeaderStyle);
        $sheet->getRowDimension($row)->setRowHeight(24);

        $dataStyle = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ];

        $row = 6;
        foreach ($orders as $order) {
            $sheet->setCellValue("A{$row}", strtoupper(substr($order->id, 0, 8)));
            $sheet->setCellValue("B{$row}", $order->created_at?->format('d/m/Y H:i'));
            $sheet->setCellValue("C{$row}", $order->cashRegister?->user?->name ?? 'N/A');
            $sheet->setCellValue("D{$row}", $order->paymentMethod?->name ?? 'N/A');
            $sheet->setCellValue("E{$row}", (float) $order->subtotal);
            $sheet->setCellValue("F{$row}", (float) $order->iva_total);
            $sheet->setCellValue("G{$row}", (float) $order->total);
            $sheet->setCellValue("H{$row}", $order->status === 'completed' ? 'Completado' : 'Cancelado');

            $sheet->getStyle("E{$row}:G{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $row++;
        }

        $lastRow = $row - 1;
        if ($lastRow >= 6) {
            $sheet->getStyle("A6:H{$lastRow}")->applyFromArray($dataStyle);

            for ($r = 6; $r <= $lastRow; $r++) {
                if ($r % 2 === 0) {
                    $sheet->getStyle("A{$r}:H{$r}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F8FAFC');
                }
            }
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'ventas_cronos_' . now()->format('Y-m-d_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
