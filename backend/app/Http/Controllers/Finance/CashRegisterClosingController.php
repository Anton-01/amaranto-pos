<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegisterClosing\SendCashRegisterClosingEmailRequest;
use App\Http\Requests\CashRegisterClosing\StoreCashRegisterClosingRequest;
use App\Mail\CashRegisterClosingMail;
use App\Models\CashRegister;
use App\Models\CashRegisterClosing;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PettyCashTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashRegisterClosingController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        $cashRegister = CashRegister::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        $paymentMethods = PaymentMethod::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $pettyCashTotal = 0;
        if ($cashRegister) {
            $pettyCashTotal = (float) PettyCashTransaction::where('user_id', $cashRegister->user_id)
                ->where('created_at', '>=', $cashRegister->opened_at)
                ->sum('amount');
        }

        return response()->json([
            'status' => 'success',
            'data'   => [
                'cash_register'    => $cashRegister,
                'payment_methods'  => $paymentMethods,
                'petty_cash_total' => round($pettyCashTotal, 2),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = CashRegisterClosing::with(['cashRegister.user', 'closedByUser'])
            ->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $closings = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'status'   => 'success',
            'data'     => $closings->items(),
            'metadata' => [
                'current_page' => $closings->currentPage(),
                'last_page'    => $closings->lastPage(),
                'total'        => $closings->total(),
            ],
        ]);
    }

    public function store(StoreCashRegisterClosingRequest $request): JsonResponse
    {
        $user = $request->user();

        $cashRegister = CashRegister::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->whereDoesntHave('closing')
            ->latest('opened_at')
            ->first();

        if (! $cashRegister) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'ERR_NO_OPEN_REGISTER',
                'message' => 'No tienes una caja registradora abierta para cerrar.',
                'errors'  => null,
                'metadata' => null,
            ], 422);
        }

        $closing = DB::transaction(function () use ($request, $cashRegister, $user) {
            $paymentMethods = PaymentMethod::where('status', 'active')
                ->orderBy('name')
                ->get();

            $expectedByMethod = Order::where('cash_register_id', $cashRegister->id)
                ->where('status', 'completed')
                ->selectRaw('payment_method_id, SUM(total) as total')
                ->groupBy('payment_method_id')
                ->get()
                ->keyBy('payment_method_id');

            $declarations = $request->declarations ?? [];

            $openingBalance = (float) $cashRegister->opening_balance;

            $pettyCashTotal = (float) PettyCashTransaction::where('user_id', $cashRegister->user_id)
                ->where('created_at', '>=', $cashRegister->opened_at)
                ->sum('amount');

            $breakdown    = [];
            $salesTotal   = 0.0;
            $declaredTotal = 0.0;

            foreach ($paymentMethods as $pm) {
                $salesAmount = (float) ($expectedByMethod[$pm->id]->total ?? 0);
                $declared    = (float) ($declarations[$pm->id] ?? 0);

                $salesTotal += $salesAmount;

                $breakdown[] = [
                    'payment_method_id' => $pm->id,
                    'name'              => $pm->name,
                    'slug'              => $pm->slug,
                    'expected'          => round($salesAmount, 2),
                    'declared'          => round($declared, 2),
                    'difference'        => round($declared - $salesAmount, 2),
                ];

                $declaredTotal += $declared;
            }

            $expectedTotal = round($openingBalance + $salesTotal - $pettyCashTotal, 2);
            $differenceTotal = round($declaredTotal - $expectedTotal, 2);

            $closing = CashRegisterClosing::create([
                'cash_register_id'  => $cashRegister->id,
                'closed_by'         => $user->id,
                'expected_amount'   => round($expectedTotal, 2),
                'declared_amount'   => round($declaredTotal, 2),
                'difference_amount' => $differenceTotal,
                'payment_breakdown' => $breakdown,
            ]);

            $cashRegister->update([
                'closed_at'              => now(),
                'actual_closing_balance' => round($declaredTotal, 2),
            ]);

            return $closing;
        });

        $closing->load(['cashRegister.user', 'closedByUser']);

        return response()->json([
            'status'   => 'success',
            'data'     => $closing,
            'metadata' => ['message' => 'Caja cerrada exitosamente. El arqueo ha sido registrado de forma inmutable.'],
        ], 201);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $query = CashRegisterClosing::with(['cashRegister.user', 'closedByUser'])
            ->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $closings = $query->get();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cierres de Caja');

        // — Institutional title row —
        $sheet->setCellValue('A1', 'CRONOS POS — REPORTE DE CIERRES DE CAJA');
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        // — Generation info row —
        $period = '';
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $period = ' | Periodo: ' . ($request->date_from ?? '—') . ' al ' . ($request->date_to ?? 'hoy');
        }
        $sheet->setCellValue('A2', 'Generado: ' . now()->format('d/m/Y H:i:s') . $period);
        $sheet->mergeCells('A2:G2');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6B7280']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        // — Column headers —
        $headers = ['ID Caja', 'Fecha / Hora Cierre', 'Operador', 'Monto Esperado', 'Monto Declarado', 'Diferencia', 'Estado'];
        foreach ($headers as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue($col . '3', $h);
        }
        $sheet->getStyle('A3:G3')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6366F1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(22);

        // — Data rows —
        $row     = 4;
        $moneyFmt = '#,##0.00 [$MXN]';

        foreach ($closings as $closing) {
            $diff = (float) $closing->difference_amount;

            $sheet->setCellValue('A' . $row, strtoupper(substr($closing->cash_register_id, 0, 8)) . '...');
            $sheet->setCellValue('B' . $row, $closing->created_at?->format('d/m/Y H:i:s') ?? '');
            $sheet->setCellValue('C' . $row, $closing->closedByUser?->name ?? '—');
            $sheet->setCellValue('D' . $row, (float) $closing->expected_amount);
            $sheet->setCellValue('E' . $row, (float) $closing->declared_amount);
            $sheet->setCellValue('F' . $row, $diff);
            $sheet->setCellValue('G' . $row, $diff < 0 ? 'FALTANTE' : ($diff > 0 ? 'SOBRANTE' : 'EXACTO'));

            $sheet->getStyle("D{$row}:F{$row}")->getNumberFormat()->setFormatCode($moneyFmt);

            if ($diff < 0) {
                $redColor = ['rgb' => 'DC2626'];
                $sheet->getStyle("F{$row}")->getFont()->getColor()->setRGB('DC2626');
                $sheet->getStyle("G{$row}")->applyFromArray(['font' => ['color' => $redColor, 'bold' => true]]);
            } elseif ($diff > 0) {
                $sheet->getStyle("F{$row}")->getFont()->getColor()->setRGB('16A34A');
                $sheet->getStyle("G{$row}")->applyFromArray(['font' => ['color' => ['rgb' => '16A34A'], 'bold' => true]]);
            }

            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
                ]);
            }

            $row++;
        }

        // — Totals footer row —
        if ($closings->count() > 0) {
            $lastData = $row - 1;
            $sheet->setCellValue('A' . $row, 'TOTAL (' . $closings->count() . ' registros)');
            $sheet->mergeCells("A{$row}:C{$row}");
            $sheet->setCellValue('D' . $row, "=SUM(D4:D{$lastData})");
            $sheet->setCellValue('E' . $row, "=SUM(E4:E{$lastData})");
            $sheet->setCellValue('F' . $row, "=SUM(F4:F{$lastData})");
            $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E7FF']],
            ]);
            $sheet->getStyle("D{$row}:F{$row}")->getNumberFormat()->setFormatCode($moneyFmt);
        }

        // — Auto-size columns —
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'cierres-caja-' . now()->format('Y-m-d') . '.xlsx';
        $writer   = new Xlsx($spreadsheet);

        return response()->streamDownload(
            function () use ($writer) { $writer->save('php://output'); },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(CashRegisterClosing $closing): \Illuminate\Http\Response
    {
        $closing->load(['cashRegister.user', 'closedByUser']);

        $pdf = Pdf::loadView('pdf.cash-register-closing', compact('closing'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download('arqueo-caja-' . strtoupper(substr($closing->cash_register_id, 0, 8)) . '.pdf');
    }

    public function sendEmail(SendCashRegisterClosingEmailRequest $request): JsonResponse
    {
        $query = CashRegisterClosing::with(['cashRegister.user', 'closedByUser'])
            ->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $closings = $query->get();
        $filters  = [
            'date_from' => $request->date_from,
            'date_to'   => $request->date_to,
        ];

        foreach ($request->emails as $email) {
            Mail::to(trim($email))->queue(new CashRegisterClosingMail($closings, $filters));
        }

        return response()->json([
            'status'   => 'success',
            'metadata' => [
                'message'    => 'Reporte enviado en cola a ' . count($request->emails) . ' destinatario(s).',
                'recipients' => count($request->emails),
            ],
        ]);
    }
}
