<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\MonthlyAnalyticsExportService;
use App\Services\MonthlyAnalyticsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/dashboard/monthly-analytics/export — role:admin,manager
 *
 * STRICTLY .XLSX. The workbook is produced by PhpSpreadsheet's Xlsx writer and
 * there is no CSV branch: the button that reaches this route exists to hand
 * somebody a report, and a comma-separated file carries neither the peso number
 * formats, nor the live SUM() formulas, nor the five sheets that make the
 * figures verifiable.
 *
 * It reads the SAME payload the modal renders (MonthlyAnalyticsService, cached
 * per month), so what you download is what you were looking at.
 */
class MonthlyAnalyticsExportController extends Controller
{
    public function __invoke(
        Request $request,
        MonthlyAnalyticsService $analytics,
        MonthlyAnalyticsExportService $exporter,
    ): StreamedResponse {
        $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $payload = $analytics->forMonth(MonthlyAnalyticsController::resolveMonth($request));

        $spreadsheet = $exporter->build($payload);
        $writer = $exporter->writer($spreadsheet);

        return response()->streamDownload(
            function () use ($writer, $spreadsheet) {
                $writer->save('php://output');
                // PhpSpreadsheet holds every cell in memory; releasing the
                // sheets here keeps a long month from staying resident until
                // the request tears down.
                $spreadsheet->disconnectWorksheets();
            },
            $exporter->filename($payload),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
