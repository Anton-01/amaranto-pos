<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegisterClosing\SendCashRegisterClosingEmailRequest;
use App\Http\Requests\CashRegisterClosing\StoreCashRegisterClosingRequest;
use App\Mail\CashRegisterClosingMail;
use App\Models\CashRegister;
use App\Models\CashRegisterClosing;
use App\Models\PaymentMethod;
use App\Models\PettyCashTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashRegisterClosingController extends Controller
{
    public function __construct(
        private readonly \App\Services\CashClosingService $closingService,
    ) {}

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
        /*
         * Both relations resolve to a person's identity on screen, so they are
         * narrowed to the three columns the table prints. Loading the bare
         * `user` model instead would attach the whole account to every row —
         * phone number, two-factor state, `password_restored_at` and the
         * soft-delete trail — for a grid that shows a name and an email.
         */
        $query = CashRegisterClosing::with([
            'cashRegister:id,user_id',
            'cashRegister.user:id,name,email',
            'closedByUser:id,name,email',
        ])->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $query->where('created_at', '>=', $from);
        }
        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->date_to)->endOfDay();
            $query->where('created_at', '<=', $to);
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

        // La aritmetica del arqueo vive en CashClosingService, compartida con
        // el cierre automatico del scheduler para que ambos cuadren identico.
        try {
            $closing = $this->closingService->close(
                $cashRegister,
                $user,
                $request->declarations ?? [],
            );
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'ERR_REGISTER_ALREADY_CLOSED')) {
                return response()->json([
                    'status'  => 'error',
                    'code'    => 'ERR_REGISTER_ALREADY_CLOSED',
                    'message' => 'La caja ya fue cerrada (posiblemente por el cierre automatico de las 21:00).',
                ], 422);
            }
            throw $e;
        }

        $closing->load(['cashRegister:id,user_id', 'cashRegister.user:id,name,email', 'closedByUser:id,name,email']);

        return response()->json([
            'status'   => 'success',
            'data'     => $closing,
            'metadata' => ['message' => 'Caja cerrada exitosamente. El arqueo ha sido registrado de forma inmutable.'],
        ], 201);
    }

    /**
     * GET /api/admin/cash-closings-audit — role:admin (exclusivo).
     *
     * Auditoria historica de cierres, manuales y automaticos. Vista de solo
     * lectura por triple diseno: este controlador no expone update/delete
     * para cierres, el modelo CashRegisterClosing lanza RuntimeException ante
     * cualquier intento de mutacion, y no existe ninguna ruta de escritura.
     */
    public function audit(Request $request): JsonResponse
    {
        $query = CashRegisterClosing::with(['cashRegister.user:id,name,email', 'closedByUser:id,name,email'])
            ->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $query->where('created_at', '>=', $from);
        }
        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->date_to)->endOfDay();
            $query->where('created_at', '<=', $to);
        }
        if ($request->filled('type') && in_array($request->type, ['manual', 'automated'], true)) {
            $query->where('is_automated', $request->type === 'automated');
        }
        if ($request->filled('difference') && $request->difference === 'nonzero') {
            $query->where('difference_amount', '!=', 0);
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->whereHas('cashRegister.user', fn ($q) => $q
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%"));
        }

        $closings = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'status' => 'success',
            'data' => $closings->items(),
            'metadata' => [
                'current_page' => $closings->currentPage(),
                'last_page' => $closings->lastPage(),
                'total' => $closings->total(),
                'summary' => [
                    'automated_count' => CashRegisterClosing::where('is_automated', true)->count(),
                    'manual_count' => CashRegisterClosing::where('is_automated', false)->count(),
                    'with_difference_count' => CashRegisterClosing::where('difference_amount', '!=', 0)->count(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/admin/cash-closings-audit/{closing} — role:admin.
     *
     * Radiografia forense completa de un cierre: desglose por metodo,
     * declarado vs calculado, responsable (humano o System Job) y la caja.
     */
    public function auditShow(CashRegisterClosing $closing): JsonResponse
    {
        $closing->load(['cashRegister.user:id,name,email', 'closedByUser:id,name,email']);

        return response()->json([
            'status' => 'success',
            'data' => $closing,
        ]);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $query = CashRegisterClosing::with(['cashRegister:id,user_id', 'cashRegister.user:id,name,email', 'closedByUser:id,name,email'])
            ->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $query->where('created_at', '>=', $from);
        }
        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->date_to)->endOfDay();
            $query->where('created_at', '<=', $to);
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
        $closing->load(['cashRegister:id,user_id', 'cashRegister.user:id,name,email', 'closedByUser:id,name,email']);

        $pdf = Pdf::loadView('pdf.cash-register-closing', compact('closing'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download('arqueo-caja-' . strtoupper(substr($closing->cash_register_id, 0, 8)) . '.pdf');
    }

    public function sendEmail(SendCashRegisterClosingEmailRequest $request): JsonResponse
    {
        $query = CashRegisterClosing::with(['cashRegister:id,user_id', 'cashRegister.user:id,name,email', 'closedByUser:id,name,email'])
            ->orderByDesc('created_at');

        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->date_from)->startOfDay();
            $query->where('created_at', '>=', $from);
        }
        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->date_to)->endOfDay();
            $query->where('created_at', '<=', $to);
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
