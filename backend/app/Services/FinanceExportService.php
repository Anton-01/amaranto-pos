<?php

namespace App\Services;

use App\Models\GlobalSetting;
use App\Support\Finance\FinanceFilters;
use App\Support\Timezone;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\DB;

/**
 * Builds the financial audit workbook.
 *
 * STRICTLY .XLSX, AND THE FORMAT IS THE REQUIREMENT. A CSV cannot carry what
 * this report is for: it has one sheet where the audit needs four, no number
 * formats so a peso column arrives as a bare float that Excel may reinterpret
 * by locale, no formulas so the totals cannot be re-verified by the person
 * reading them, and no way to distinguish a heading from data. The workbook is
 * written with PhpSpreadsheet's Xlsx writer and there is no CSV branch anywhere
 * in this class or the controller that calls it.
 *
 * FOUR SHEETS, BECAUSE AN AUDIT IS A CHAIN. "Resumen" states the conclusion;
 * "Órdenes" is the evidence it was computed from; "Cierres de Caja" is how that
 * money was physically accounted for at the end of each shift; "Deducciones" is
 * what left the investment fund. Someone checking the remainder can walk from
 * the headline figure to the individual receipts without leaving the file.
 */
class FinanceExportService
{
    /** Peso format applied to every money cell, so Excel never guesses. */
    private const MONEY_FORMAT = '#,##0.00 [$MXN]';

    private const HEADER_FILL = '4F46E5';

    private const SUBHEADER_FILL = '6366F1';

    public function build(FinanceFilters $filters): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $taxRate = $this->readSetting('tax_rate', 'rate', 0.16);
        $investmentPct = (int) $this->readSetting('investment_split', 'investment_pct', 70);
        $profitPct = (int) $this->readSetting('investment_split', 'profit_pct', 30);

        $orders = $this->fetchOrders($filters);
        $closings = $this->fetchClosings($filters);
        $deductions = $this->fetchDeductions($filters);

        $this->buildSummarySheet(
            $spreadsheet->getActiveSheet(),
            $filters,
            $orders,
            $deductions,
            $taxRate,
            $investmentPct,
            $profitPct,
        );

        $this->buildOrdersSheet($spreadsheet->createSheet(), $filters, $orders);
        $this->buildClosingsSheet($spreadsheet->createSheet(), $filters, $closings);
        $this->buildDeductionsSheet($spreadsheet->createSheet(), $filters, $deductions);

        // The workbook must open on the conclusion, not on whichever sheet was
        // written last.
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function filename(FinanceFilters $filters): string
    {
        return 'reporte-financiero-'
            .$filters->from->format('Ymd').'-'.$filters->to->format('Ymd')
            .'.xlsx';
    }

    /** The one place the writer is chosen. Xlsx, never Csv. */
    public function writer(Spreadsheet $spreadsheet): Xlsx
    {
        return new Xlsx($spreadsheet);
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    /** @return \Illuminate\Support\Collection<int, object> */
    private function fetchOrders(FinanceFilters $filters)
    {
        $query = DB::table('orders')
            ->join('payment_methods', 'orders.payment_method_id', '=', 'payment_methods.id')
            ->leftJoin('cash_registers', 'orders.cash_register_id', '=', 'cash_registers.id')
            ->leftJoin('users', 'cash_registers.user_id', '=', 'users.id')
            ->select(
                'orders.id',
                'orders.created_at',
                'orders.subtotal',
                'orders.iva_total',
                'orders.total',
                'orders.discount_total',
                'orders.status',
                'orders.cash_register_id',
                'orders.table_name_at_sale',
                'payment_methods.name as payment_name',
                'users.name as operator_name',
            )
            ->where('orders.status', 'completed')
            ->orderBy('orders.created_at');

        $filters->applyToOrders($query);

        return $query->get();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function fetchClosings(FinanceFilters $filters)
    {
        $query = DB::table('cash_register_closings')
            ->leftJoin('cash_registers', 'cash_register_closings.cash_register_id', '=', 'cash_registers.id')
            ->leftJoin('users as operators', 'cash_registers.user_id', '=', 'operators.id')
            ->leftJoin('users as closers', 'cash_register_closings.closed_by', '=', 'closers.id')
            ->select(
                'cash_register_closings.id',
                'cash_register_closings.cash_register_id',
                'cash_register_closings.created_at',
                'cash_register_closings.expected_amount',
                'cash_register_closings.declared_amount',
                'cash_register_closings.difference_amount',
                'cash_register_closings.payment_breakdown',
                'cash_register_closings.is_automated',
                'cash_registers.opening_balance',
                'operators.name as operator_name',
                'closers.name as closed_by_name',
            )
            ->whereBetween('cash_register_closings.created_at', [$filters->from, $filters->to])
            ->orderBy('cash_register_closings.created_at');

        // A closing belongs to one register and one operator, so those two
        // filters translate directly. A payment method does not select a
        // closing — every closing covers all of them — so it is not applied.
        if ($filters->cashRegisterId !== null) {
            $query->where('cash_register_closings.cash_register_id', $filters->cashRegisterId);
        }

        if ($filters->userId !== null) {
            $query->where('cash_registers.user_id', $filters->userId);
        }

        return $query->get();
    }

    /**
     * Petty cash and stock movements, unified into one shape so the sheet can
     * present the investment fund's outflows as a single ledger.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchDeductions(FinanceFilters $filters)
    {
        $pettyCash = DB::table('petty_cash_transactions')
            ->leftJoin('users', 'petty_cash_transactions.user_id', '=', 'users.id')
            /*
             * Every column of this branch is cast to text where the other
             * branch carries a PostgreSQL enum. A UNION unifies the two
             * branches column by column, and an untyped literal next to an
             * enum makes Postgres try to coerce the literal INTO that enum —
             * which fails, because 'petty_cash' is not a member of
             * stock_movement_type. Casting both sides to text makes the two
             * ledgers genuinely union-compatible instead of accidentally so.
             */
            ->select(
                'petty_cash_transactions.created_at',
                DB::raw("CAST('petty_cash' AS text) as kind"),
                DB::raw('CAST(petty_cash_transactions.description AS text) as concept'),
                DB::raw('petty_cash_transactions.amount as amount'),
                DB::raw('1 as quantity'),
                DB::raw('CAST(users.name AS text) as operator_name'),
                DB::raw('CAST(NULL AS text) as reason'),
            );

        $filters->applyToDeductions($pettyCash, 'petty_cash_transactions');

        $stock = DB::table('stock_movements')
            ->leftJoin('users', 'stock_movements.user_id', '=', 'users.id')
            ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
            ->select(
                'stock_movements.created_at',
                DB::raw('CAST(stock_movements.type AS text) as kind'),
                DB::raw('CAST(products.name AS text) as concept'),
                DB::raw('(stock_movements.cost_price_at_movement * stock_movements.quantity) as amount'),
                'stock_movements.quantity',
                DB::raw('CAST(users.name AS text) as operator_name'),
                DB::raw('CAST(stock_movements.reason AS text) as reason'),
            )
            ->whereIn('stock_movements.type', ['purchase_input', 'merma_output']);

        $filters->applyToDeductions($stock, 'stock_movements');

        return $pettyCash->unionAll($stock)->orderBy('created_at')->get();
    }

    // ------------------------------------------------------------------
    // Sheets
    // ------------------------------------------------------------------

    private function buildSummarySheet(
        Worksheet $sheet,
        FinanceFilters $filters,
        $orders,
        $deductions,
        float $taxRate,
        int $investmentPct,
        int $profitPct,
    ): void {
        $sheet->setTitle('Resumen');

        $gross = round((float) $orders->sum('total'), 2);
        $net = round((float) $orders->sum('subtotal'), 2);
        $tax = round((float) $orders->sum('iva_total'), 2);
        $discounts = round((float) $orders->sum('discount_total'), 2);

        $investmentFund = round($net * ($investmentPct / 100), 2);
        $netProfit = round($net * ($profitPct / 100), 2);

        $pettyCash = round((float) $deductions->where('kind', 'petty_cash')->sum('amount'), 2);
        $purchases = round((float) $deductions->where('kind', 'purchase_input')->sum('amount'), 2);
        $merma = round((float) $deductions->where('kind', 'merma_output')->sum('amount'), 2);
        $totalDeductions = round($pettyCash + $purchases, 2);

        $this->titleBlock($sheet, 'CRONOS POS — REPORTE FINANCIERO', $filters, 'D');

        $row = 4;
        $row = $this->sectionHeader($sheet, $row, 'INGRESOS DEL PERIODO', 'D');
        $row = $this->figure($sheet, $row, 'Ingreso Bruto (con IVA)', $gross);
        $row = $this->figure($sheet, $row, 'IVA trasladado ('.round($taxRate * 100).'%)', $tax);
        $row = $this->figure($sheet, $row, 'Ingreso Neto (sin IVA)', $net, bold: true);
        $row = $this->figure($sheet, $row, 'Descuentos aplicados', $discounts);
        $row = $this->figure($sheet, $row, 'Órdenes completadas', $orders->count(), money: false);

        $row++;
        $row = $this->sectionHeader($sheet, $row, "SEGMENTACIÓN {$investmentPct}/{$profitPct}", 'D');
        $row = $this->figure($sheet, $row, "Fondo de Inversión ({$investmentPct}% del neto)", $investmentFund, bold: true);
        $row = $this->figure($sheet, $row, "Utilidad Real ({$profitPct}% del neto)", $netProfit, bold: true);

        $row++;
        $row = $this->sectionHeader($sheet, $row, 'DEDUCCIONES DEL FONDO DE INVERSIÓN', 'D');
        $row = $this->figure($sheet, $row, '(-) Caja chica', $pettyCash);
        $row = $this->figure($sheet, $row, '(-) Compras de stock', $purchases);
        $row = $this->figure($sheet, $row, 'Total de deducciones', $totalDeductions, bold: true);

        $remainingRow = $row;
        $row = $this->figure($sheet, $row, 'REMANENTE DEL FONDO', round($investmentFund - $totalDeductions, 2), bold: true);
        $sheet->getStyle("A{$remainingRow}:B{$remainingRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E7FF']],
        ]);

        $row++;
        $row = $this->sectionHeader($sheet, $row, 'INFORMATIVO (no deduce del fondo)', 'D');
        $row = $this->figure($sheet, $row, 'Pérdidas por merma', $merma);

        /*
         * When a payment-method or register filter is active, the deduction
         * figures above cover the whole business while the income figures cover
         * only the filtered slice — so the remainder is not a meaningful
         * number. Saying so inside the file matters more than saying it in the
         * UI: the spreadsheet is what gets emailed, printed and argued over
         * months later, long after the screen that produced it is gone.
         */
        if (! $filters->deductionsAreComparable()) {
            $row += 2;
            $sheet->setCellValue("A{$row}", 'AVISO: hay filtros activos (método de pago o caja) que los movimientos '
                .'de caja chica y compras de stock no pueden honrar, porque esos movimientos no pertenecen a un '
                .'método de pago ni a una caja. Los ingresos están filtrados y las deducciones no: el REMANENTE de '
                .'esta hoja no es comparable. Para una lectura contable del remanente, exporta sin esos filtros.');
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '92400E'], 'size' => 9],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(58);
        }

        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(14);
    }

    private function buildOrdersSheet(Worksheet $sheet, FinanceFilters $filters, $orders): void
    {
        $sheet->setTitle('Órdenes');
        $this->titleBlock($sheet, 'DETALLE DE ÓRDENES', $filters, 'H');

        $headers = ['Folio', 'Fecha / Hora', 'Operador', 'Método de Pago', 'Mesa', 'Subtotal (Neto)', 'IVA', 'Total (Bruto)'];
        $this->headerRow($sheet, 3, $headers);

        $row = 4;
        foreach ($orders as $order) {
            $sheet->setCellValue('A'.$row, strtoupper(substr((string) $order->id, 0, 8)));
            $sheet->setCellValue('B'.$row, $this->localTime($order->created_at));
            $sheet->setCellValue('C'.$row, $order->operator_name ?? '—');
            $sheet->setCellValue('D'.$row, $order->payment_name ?? '—');
            $sheet->setCellValue('E'.$row, $order->table_name_at_sale ?? '—');
            $sheet->setCellValue('F'.$row, (float) $order->subtotal);
            $sheet->setCellValue('G'.$row, (float) $order->iva_total);
            $sheet->setCellValue('H'.$row, (float) $order->total);

            $sheet->getStyle("F{$row}:H{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $this->zebra($sheet, $row, 'H');
            $row++;
        }

        $this->totalsRow($sheet, $row, $orders->count(), ['F', 'G', 'H'], 'E', 'H');
        $this->autoSize($sheet, 'A', 'H');
        $sheet->freezePane('A4');
    }

    private function buildClosingsSheet(Worksheet $sheet, FinanceFilters $filters, $closings): void
    {
        $sheet->setTitle('Cierres de Caja');
        $this->titleBlock($sheet, 'ARQUEOS Y DESGLOSE POR MÉTODO', $filters, 'I');

        $headers = [
            'ID Caja', 'Fecha / Hora Cierre', 'Operador', 'Cerró', 'Origen',
            'Fondo Inicial', 'Esperado', 'Declarado', 'Diferencia',
        ];
        $this->headerRow($sheet, 3, $headers);

        $row = 4;
        foreach ($closings as $closing) {
            $difference = (float) $closing->difference_amount;

            $sheet->setCellValue('A'.$row, strtoupper(substr((string) $closing->cash_register_id, 0, 8)));
            $sheet->setCellValue('B'.$row, $this->localTime($closing->created_at));
            $sheet->setCellValue('C'.$row, $closing->operator_name ?? '—');
            $sheet->setCellValue('D'.$row, $closing->closed_by_name ?? '—');
            $sheet->setCellValue('E'.$row, $closing->is_automated ? 'Automático (21:00)' : 'Manual');
            $sheet->setCellValue('F'.$row, (float) ($closing->opening_balance ?? 0));
            $sheet->setCellValue('G'.$row, (float) $closing->expected_amount);
            $sheet->setCellValue('H'.$row, (float) $closing->declared_amount);
            $sheet->setCellValue('I'.$row, $difference);

            $sheet->getStyle("F{$row}:I{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

            // A shortfall is the one figure on this sheet somebody has to act
            // on, so it is the one figure that gets colour.
            if ($difference < 0) {
                $sheet->getStyle("I{$row}")->applyFromArray(['font' => ['color' => ['rgb' => 'DC2626'], 'bold' => true]]);
            } elseif ($difference > 0) {
                $sheet->getStyle("I{$row}")->applyFromArray(['font' => ['color' => ['rgb' => '16A34A'], 'bold' => true]]);
            }

            $this->zebra($sheet, $row, 'I');
            $row++;

            // Per-method arqueo, indented under its closing. This is the
            // "distribución exacta" the report exists for: without it the sheet
            // says a drawer was short $200 and cannot say in which tender.
            $breakdown = json_decode((string) $closing->payment_breakdown, true);

            if (is_array($breakdown) && $breakdown !== []) {
                foreach ($breakdown as $method) {
                    $sheet->setCellValue('B'.$row, '    ↳ '.($method['name'] ?? $method['slug'] ?? '—'));
                    $sheet->setCellValue('G'.$row, (float) ($method['expected'] ?? 0));
                    $sheet->setCellValue('H'.$row, (float) ($method['declared'] ?? 0));
                    $sheet->setCellValue('I'.$row, (float) ($method['difference'] ?? 0));

                    $sheet->getStyle("G{$row}:I{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
                    $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
                        'font' => ['size' => 9, 'color' => ['rgb' => '64748B'], 'italic' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
                    ]);
                    $row++;
                }
            }
        }

        $this->autoSize($sheet, 'A', 'I');
        $sheet->freezePane('A4');
    }

    private function buildDeductionsSheet(Worksheet $sheet, FinanceFilters $filters, $deductions): void
    {
        $sheet->setTitle('Deducciones');
        $this->titleBlock($sheet, 'SALIDAS DEL FONDO DE INVERSIÓN', $filters, 'F');

        $headers = ['Fecha / Hora', 'Tipo', 'Concepto', 'Cantidad', 'Motivo', 'Importe'];
        $this->headerRow($sheet, 3, $headers);

        $kindLabels = [
            'petty_cash' => 'Caja chica',
            'purchase_input' => 'Compra de stock',
            'merma_output' => 'Merma (informativo)',
        ];

        $reasonLabels = [
            'expired' => 'Caducado',
            'damaged_spilled' => 'Dañado / Derramado',
            'internal_consumption' => 'Consumo interno',
            'theft_loss' => 'Robo / Extravío',
        ];

        $row = 4;
        foreach ($deductions as $item) {
            $sheet->setCellValue('A'.$row, $this->localTime($item->created_at));
            $sheet->setCellValue('B'.$row, $kindLabels[$item->kind] ?? $item->kind);
            $sheet->setCellValue('C'.$row, $item->concept ?? '—');
            $sheet->setCellValue('D'.$row, (int) $item->quantity);
            $sheet->setCellValue('E'.$row, $reasonLabels[$item->reason] ?? ($item->reason ?? '—'));
            $sheet->setCellValue('F'.$row, (float) $item->amount);

            $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

            // Merma is listed but does NOT deduct from the fund, so it is
            // greyed to keep a reader from adding it into the total by eye.
            if ($item->kind === 'merma_output') {
                $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                    'font' => ['color' => ['rgb' => '92400E'], 'italic' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFBEB']],
                ]);
            } else {
                $this->zebra($sheet, $row, 'F');
            }

            $row++;
        }

        $this->autoSize($sheet, 'A', 'F');
        $sheet->freezePane('A4');
    }

    // ------------------------------------------------------------------
    // Formatting helpers
    // ------------------------------------------------------------------

    private function titleBlock(Worksheet $sheet, string $title, FinanceFilters $filters, string $lastColumn): void
    {
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $caption = 'Periodo: '.$filters->periodLabel()
            .' | Generado: '.Carbon::now()->format('d/m/Y H:i:s');

        if ($filters->hasAdvancedFilters()) {
            $caption .= ' | Filtros avanzados aplicados';
        }

        $sheet->setCellValue('A2', $caption);
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6B7280']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
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

    private function figure(Worksheet $sheet, int $row, string $label, float|int $value, bool $bold = false, bool $money = true): int
    {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $value);

        if ($money) {
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        if ($bold) {
            $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        }

        return $row + 1;
    }

    /**
     * Totals row backed by real SUM() formulas rather than a precomputed
     * number: an auditor can click the cell and watch Excel re-derive it from
     * the rows above, which is exactly the check the file exists to survive.
     *
     * @param  array<int, string>  $columns
     */
    private function totalsRow(Worksheet $sheet, int $row, int $count, array $columns, string $labelLast, string $lastColumn): void
    {
        if ($count === 0) {
            return;
        }

        $lastData = $row - 1;

        $sheet->setCellValue("A{$row}", "TOTAL ({$count} registros)");
        $sheet->mergeCells("A{$row}:{$labelLast}{$row}");

        foreach ($columns as $column) {
            $sheet->setCellValue("{$column}{$row}", "=SUM({$column}4:{$column}{$lastData})");
            $sheet->getStyle("{$column}{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E7FF']],
        ]);
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

    /**
     * Timestamps are rendered in the business timezone.
     *
     * PostgreSQL hands back a `timestamptz` string; parsing it and shifting to
     * the app zone is what keeps a sale rung up at 20:30 from appearing in the
     * spreadsheet as 02:30 of the following day.
     */
    private function localTime(?string $timestamp): string
    {
        return $timestamp === null
            ? ''
            : Carbon::parse($timestamp)->timezone(Timezone::app())->format('d/m/Y H:i:s');
    }

    private function readSetting(string $key, string $field, float|int $default): float|int
    {
        $setting = GlobalSetting::where('key', $key)->first();

        return $setting ? ($setting->value[$field] ?? $default) : $default;
    }
}
