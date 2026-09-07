<?php

namespace App\Services;

use App\Support\Timezone;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the monthly analytics workbook behind "Exportar Reporte".
 *
 * STRICTLY .XLSX, AND THE FORMAT IS THE REQUIREMENT. The button used to hand
 * back a client-side CSV: one flat sheet, no number formats (so a peso column
 * arrived as a bare float that Excel reinterprets by locale), no formulas, and
 * no way to tell a heading from data. What gets emailed to a partner or filed
 * with an accountant has to read as a report, not as a dump — so the workbook
 * is written with PhpSpreadsheet's Xlsx writer and there is no CSV branch in
 * this class or in the controller that calls it.
 *
 * FIVE SHEETS, ONE PER QUESTION. "Resumen Ejecutivo" states the month and how
 * it compares with the previous one; the four that follow are the evidence
 * behind each chart of the modal, in the same order the modal shows them, so
 * somebody reading the file can follow the screen they were shown.
 *
 * The visual language is deliberately the same as FinanceExportService: indigo
 * banner, indigo header rows, zebra body, peso format, live SUM() totals. Two
 * financial exports from the same system must look like they came from the
 * same system.
 */
class MonthlyAnalyticsExportService
{
    /** Peso format applied to every money cell, so Excel never guesses. */
    private const MONEY_FORMAT = '#,##0.00 [$MXN]';

    private const PERCENT_FORMAT = '+0.0"%";-0.0"%";0.0"%"';

    private const HEADER_FILL = '4F46E5';

    private const SUBHEADER_FILL = '6366F1';

    private const ACCENT_FILL = 'E0E7FF';

    private const CAPTION_FILL = 'EEF2FF';

    /** @param array<string, mixed> $payload */
    public function build(array $payload): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $spreadsheet->getProperties()
            ->setCreator('Cronos POS')
            ->setTitle('Analitica Financiera '.$payload['month'])
            ->setSubject('Reporte financiero mensual')
            ->setCompany('Cronos POS');

        $this->buildSummarySheet($spreadsheet->getActiveSheet(), $payload);
        $this->buildPaymentMethodsSheet($spreadsheet->createSheet(), $payload);
        $this->buildTopProductsSheet($spreadsheet->createSheet(), $payload);
        $this->buildPeakHoursSheet($spreadsheet->createSheet(), $payload);
        $this->buildDailyTrendSheet($spreadsheet->createSheet(), $payload);

        // The workbook must open on the conclusion, not on whichever sheet was
        // written last.
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @param array<string, mixed> $payload */
    public function filename(array $payload): string
    {
        return 'analitica-financiera-'.$payload['month'].'.xlsx';
    }

    /** The one place the writer is chosen. Xlsx, never Csv. */
    public function writer(Spreadsheet $spreadsheet): Xlsx
    {
        return new Xlsx($spreadsheet);
    }

    // ------------------------------------------------------------------
    // Sheets
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    private function buildSummarySheet(Worksheet $sheet, array $payload): void
    {
        $sheet->setTitle('Resumen Ejecutivo');
        $this->titleBlock($sheet, 'CRONOS POS — ANALÍTICA FINANCIERA', $payload, 'C');

        $totals = $payload['totals'];
        $previous = $payload['previous'];
        $comparison = $payload['comparison'];

        $row = 4;
        $row = $this->sectionHeader($sheet, $row, 'INDICADORES DEL MES', 'C');

        // The comparison column is the reason this sheet exists: a total on its
        // own does not say whether the month went well.
        $this->headerRow($sheet, $row, ['Métrica', 'Valor', 'vs. mes previo']);
        $row++;

        $row = $this->figure($sheet, $row, 'Ventas Totales (bruto)', $totals['total_sales'], $comparison['sales_delta_pct'], bold: true);
        $row = $this->figure($sheet, $row, 'Ingreso Neto (sin IVA)', $totals['net_sales']);
        $row = $this->figure($sheet, $row, 'IVA Recaudado', $totals['tax_total']);
        $row = $this->figure($sheet, $row, 'Descuentos Otorgados', $totals['discount_total']);
        $row = $this->figure($sheet, $row, 'Órdenes Completadas', $totals['order_count'], $comparison['orders_delta_pct'], money: false);
        $row = $this->figure($sheet, $row, 'Ticket Promedio', $totals['avg_ticket'], $comparison['avg_ticket_delta_pct'], bold: true);

        $row++;
        $row = $this->sectionHeader($sheet, $row, 'MES PREVIO ('.$previous['month'].') — BASE DE COMPARACIÓN', 'C');
        $row = $this->figure($sheet, $row, 'Ventas Totales (bruto)', $previous['total_sales']);
        $row = $this->figure($sheet, $row, 'Órdenes Completadas', $previous['order_count'], money: false);
        $row = $this->figure($sheet, $row, 'Ticket Promedio', $previous['avg_ticket']);

        /*
         * A month with no sales is a legitimate answer, and the file has to say
         * so in words: a reader who finds four empty sheets cannot tell an idle
         * month from a broken export.
         */
        if ($totals['order_count'] === 0) {
            $row++;
            $this->notice($sheet, $row, 'C', 'Sin órdenes completadas en el periodo. Las hojas de detalle de este '
                .'libro están vacías por esa razón, no por un error de extracción.');
        }

        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(18);
    }

    /** @param array<string, mixed> $payload */
    private function buildPaymentMethodsSheet(Worksheet $sheet, array $payload): void
    {
        $sheet->setTitle('Métodos de Pago');
        $this->titleBlock($sheet, 'DISTRIBUCIÓN POR MÉTODO DE PAGO', $payload, 'D');
        $this->headerRow($sheet, 3, ['Método', 'Órdenes', 'Total Cobrado', '% del Total']);

        $grand = (float) $payload['totals']['total_sales'];
        $row = 4;

        foreach ($payload['by_payment_method'] as $method) {
            $sheet->setCellValue('A'.$row, $method['name']);
            $sheet->setCellValue('B'.$row, $method['order_count']);
            $sheet->setCellValue('C'.$row, (float) $method['total']);
            $sheet->setCellValue('D'.$row, $grand > 0 ? round($method['total'] / $grand, 4) : 0);

            $sheet->getStyle('C'.$row)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('D'.$row)->getNumberFormat()->setFormatCode('0.0%');
            $this->zebra($sheet, $row, 'D');
            $row++;
        }

        $this->totalsRow($sheet, $row, count($payload['by_payment_method']), ['B' => 'plain', 'C' => 'money'], 'A', 'D');
        $this->autoSize($sheet, 'A', 'D');
        $sheet->freezePane('A4');
    }

    /** @param array<string, mixed> $payload */
    private function buildTopProductsSheet(Worksheet $sheet, array $payload): void
    {
        $sheet->setTitle('Top Productos');
        $this->titleBlock($sheet, 'TOP 10 PRODUCTOS POR INGRESO', $payload, 'D');
        $this->headerRow($sheet, 3, ['#', 'Producto', 'Piezas Vendidas', 'Ingreso']);

        $row = 4;
        foreach ($payload['top_products'] as $index => $product) {
            $sheet->setCellValue('A'.$row, $index + 1);
            $sheet->setCellValue('B'.$row, $product['name']);
            $sheet->setCellValue('C'.$row, $product['quantity_sold']);
            $sheet->setCellValue('D'.$row, (float) $product['revenue']);

            $sheet->getStyle('D'.$row)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->zebra($sheet, $row, 'D');
            $row++;
        }

        $this->totalsRow($sheet, $row, count($payload['top_products']), ['C' => 'plain', 'D' => 'money'], 'B', 'D');
        $this->autoSize($sheet, 'A', 'D');
        $sheet->freezePane('A4');
    }

    /** @param array<string, mixed> $payload */
    private function buildPeakHoursSheet(Worksheet $sheet, array $payload): void
    {
        $sheet->setTitle('Horas Pico');
        $this->titleBlock($sheet, 'VENTAS POR HORA DEL DÍA', $payload, 'C');
        $this->headerRow($sheet, 3, ['Hora', 'Órdenes', 'Total']);

        // Only hours with movement. Twenty-four rows of zeros would bury the
        // three that the staffing decision actually turns on.
        $hours = array_values(array_filter($payload['peak_hours'], fn ($h) => $h['orders'] > 0));
        $busiest = $hours === [] ? null : max(array_column($hours, 'orders'));

        $row = 4;
        foreach ($hours as $hour) {
            $sheet->setCellValue('A'.$row, $hour['hour']);
            $sheet->setCellValue('B'.$row, $hour['orders']);
            $sheet->setCellValue('C'.$row, (float) $hour['total']);

            $sheet->getStyle('C'.$row)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $this->zebra($sheet, $row, 'C');

            // The peak itself is the finding, so it is the one row with colour.
            if ($hour['orders'] === $busiest) {
                $sheet->getStyle("A{$row}:C{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '3730A3']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ACCENT_FILL]],
                ]);
            }

            $row++;
        }

        $this->totalsRow($sheet, $row, count($hours), ['B' => 'plain', 'C' => 'money'], 'A', 'C');
        $this->autoSize($sheet, 'A', 'C');
        $sheet->freezePane('A4');
    }

    /** @param array<string, mixed> $payload */
    private function buildDailyTrendSheet(Worksheet $sheet, array $payload): void
    {
        $sheet->setTitle('Tendencia Diaria');
        $this->titleBlock($sheet, 'TENDENCIA DIARIA DE INGRESOS', $payload, 'D');
        $this->headerRow($sheet, 3, ['Día', 'Órdenes', 'Total', 'Ticket Promedio']);

        $row = 4;
        foreach ($payload['daily_trend'] as $day) {
            $sheet->setCellValue('A'.$row, $day['day']);
            $sheet->setCellValue('B'.$row, $day['orders']);
            $sheet->setCellValue('C'.$row, (float) $day['total']);
            $sheet->setCellValue('D'.$row, $day['orders'] > 0 ? round($day['total'] / $day['orders'], 2) : 0);

            $sheet->getStyle("C{$row}:D{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $this->zebra($sheet, $row, 'D');
            $row++;
        }

        $this->totalsRow($sheet, $row, count($payload['daily_trend']), ['B' => 'plain', 'C' => 'money'], 'A', 'D');
        $this->autoSize($sheet, 'A', 'D');
        $sheet->freezePane('A4');
    }

    // ------------------------------------------------------------------
    // Chrome
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    private function titleBlock(Worksheet $sheet, string $title, array $payload, string $lastColumn): void
    {
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->setCellValue('A2', 'Periodo: '.$payload['month_label']
            .' | Generado: '.Carbon::now()->timezone(Timezone::app())->format('d/m/Y H:i:s'));
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6B7280']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::CAPTION_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);
    }

    /** @param array<int, string> $headers */
    private function headerRow(Worksheet $sheet, int $row, array $headers): void
    {
        /*
         * The [column, row] coordinate form, not setCellValueByColumnAndRow():
         * that helper was removed in PhpSpreadsheet 3.x, which this project
         * pins.
         */
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, $row], $header);
        }

        $last = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SUBHEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function sectionHeader(Worksheet $sheet, int $row, string $label, string $lastColumn): int
    {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SUBHEADER_FILL]],
        ]);

        return $row + 1;
    }

    /** One "metric | value | delta" line of the summary sheet. */
    private function figure(
        Worksheet $sheet,
        int $row,
        string $label,
        float|int $value,
        ?float $deltaPct = null,
        bool $bold = false,
        bool $money = true,
    ): int {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $value);

        if ($money) {
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        if ($deltaPct === null) {
            $sheet->setCellValue("C{$row}", '—');
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        } else {
            $sheet->setCellValue("C{$row}", $deltaPct);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
            $sheet->getStyle("C{$row}")->getFont()
                ->setBold(true)
                ->getColor()->setRGB($deltaPct >= 0 ? '16A34A' : 'DC2626');
        }

        if ($bold) {
            $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        }

        $sheet->getStyle("A{$row}:C{$row}")->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('E2E8F0');

        return $row + 1;
    }

    /**
     * Totals row backed by real SUM() formulas rather than a precomputed
     * number: a reader can click the cell and watch Excel re-derive it from the
     * rows above, which is exactly the check the file exists to survive.
     *
     * @param  array<string, string>  $columns  column letter => 'money'|'plain'
     */
    private function totalsRow(Worksheet $sheet, int $row, int $count, array $columns, string $labelLast, string $lastColumn): void
    {
        if ($count === 0) {
            $this->notice($sheet, $row, $lastColumn, 'Sin registros en el periodo.');

            return;
        }

        $lastData = $row - 1;

        $sheet->setCellValue('A'.$row, "TOTAL ({$count} registros)");
        $sheet->mergeCells("A{$row}:{$labelLast}{$row}");

        foreach ($columns as $column => $kind) {
            $sheet->setCellValue("{$column}{$row}", "=SUM({$column}4:{$column}{$lastData})");

            if ($kind === 'money') {
                $sheet->getStyle("{$column}{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            }
        }

        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ACCENT_FILL]],
        ]);
    }

    private function notice(Worksheet $sheet, int $row, string $lastColumn, string $message): void
    {
        $sheet->setCellValue('A'.$row, $message);
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->getStyle('A'.$row)->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '92400E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
    }

    private function zebra(Worksheet $sheet, int $row, string $lastColumn): void
    {
        if ($row % 2 === 0) {
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
            ]);
        }
    }

    private function autoSize(Worksheet $sheet, string $first, string $last): void
    {
        foreach (range($first, $last) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
