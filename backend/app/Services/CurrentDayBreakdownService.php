<?php

namespace App\Services;

use App\Models\GlobalSetting;
use App\Support\Finance\FinanceFilters;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Money flow of the business day currently in progress, register by register.
 *
 * THE PROBLEM THIS SOLVES. Until the 21:00 auto-closing runs, the day's money
 * lives in two different states: what has already been settled into an
 * immutable closing record, and what is still live inside a drawer somebody has
 * open. A panel that reads only `cash_register_closings` under-reports the
 * business all day and then jumps at night; a panel that reads only `orders`
 * cannot show the arqueo — the expected-versus-declared comparison that is the
 * whole point of a closing. Both readings are partial, so this service returns
 * both and labels which is which.
 *
 * WHICH REGISTERS BELONG TO "TODAY". The union of two sets: registers that
 * produced sales today, and registers whose closing was performed today. The
 * second set matters because a drawer opened at 22:00 yesterday and closed at
 * 01:00 today has its arqueo dated today — leaving it out would make the
 * closings listed here disagree with the closings history screen for the same
 * date.
 *
 * WHY THE FIGURES ARE RECOMPUTED AND NOT READ FROM `payment_breakdown`. The
 * stored breakdown is an immutable ledger of EXPECTED versus DECLARED cash, and
 * it works on gross totals because that is what a person counts in a drawer.
 * The 70/30 segmentation works on the net subtotal, which the breakdown does not
 * carry. Deriving the split from gross would inflate every figure by the tax
 * rate — so the sales aggregates come from `orders` and the arqueo comes from
 * the closing, each from the source that actually holds the truth.
 */
class CurrentDayBreakdownService
{
    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $day = null): array
    {
        $day ??= Carbon::now();
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        [$investmentPct, $profitPct] = $this->readSplit();

        $salesByRegister = $this->salesByRegister($from, $to);
        $closings = $this->closingsPerformedOn($from, $to);
        $registers = $this->registerContext(
            $salesByRegister->keys()->merge($closings->keys())->unique()
        );

        $rows = $registers
            ->map(fn (object $register) => $this->buildRegisterRow(
                $register,
                $salesByRegister->get($register->id),
                $closings->get($register->id),
                $investmentPct,
                $profitPct,
            ))
            /*
             * Open drawers first. They are the only rows an operator can still
             * act on; a closed register is history and can wait below the fold.
             * Within each group, most recently opened first.
             *
             * The [key, direction] pair form, not comparator closures: sortBy()
             * calls a closure as ($item, $key) to extract a sort VALUE, so a
             * two-argument comparator silently receives the collection index as
             * its second argument and orders by nothing at all.
             */
            ->sortBy([
                ['is_closed', 'asc'],
                ['opened_at', 'desc'],
            ])
            ->values();

        return [
            'business_date' => $from->toDateString(),
            'generated_at' => Carbon::now()->toIso8601String(),
            'split' => ['investment_pct' => $investmentPct, 'profit_pct' => $profitPct],
            'totals' => $this->dayTotals($rows, $investmentPct, $profitPct),
            'payment_methods' => $this->dayPaymentMethods($rows),
            'registers' => $rows->all(),
            'counters' => [
                'registers' => $rows->count(),
                'closed' => $rows->where('is_closed', true)->count(),
                'open' => $rows->where('is_closed', false)->count(),
                'automated_closings' => $rows->where('closing.is_automated', true)->count(),
            ],
        ];
    }

    /**
     * Sales of the day aggregated by register and payment method.
     *
     * One query for the whole day rather than one per register: a busy floor
     * runs a handful of drawers, but the N+1 shape would still put the panel's
     * latency at the mercy of how many cashiers happen to be on shift.
     *
     * @return Collection<string, Collection<int, object>>
     */
    private function salesByRegister(Carbon $from, Carbon $to): Collection
    {
        return DB::table('orders')
            ->join('payment_methods', 'orders.payment_method_id', '=', 'payment_methods.id')
            ->select(
                'orders.cash_register_id',
                'payment_methods.slug as payment_slug',
                'payment_methods.name as payment_name',
                DB::raw('COALESCE(SUM(orders.total), 0) as gross'),
                DB::raw('COALESCE(SUM(orders.subtotal), 0) as net'),
                DB::raw('COALESCE(SUM(orders.iva_total), 0) as tax'),
                DB::raw('COALESCE(SUM(orders.discount_total), 0) as discounts'),
                DB::raw('COUNT(*) as order_count'),
            )
            // 'completed' and not settled(): a canceled ticket took no money,
            // and a table still open has not taken it yet.
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('orders.cash_register_id', 'payment_methods.slug', 'payment_methods.name')
            ->get()
            ->groupBy('cash_register_id');
    }

    /**
     * Closings performed within the day, keyed by register.
     *
     * @return Collection<string, object>
     */
    private function closingsPerformedOn(Carbon $from, Carbon $to): Collection
    {
        return DB::table('cash_register_closings')
            ->leftJoin('users', 'cash_register_closings.closed_by', '=', 'users.id')
            ->select(
                'cash_register_closings.id',
                'cash_register_closings.cash_register_id',
                'cash_register_closings.expected_amount',
                'cash_register_closings.declared_amount',
                'cash_register_closings.difference_amount',
                'cash_register_closings.payment_breakdown',
                'cash_register_closings.is_automated',
                'cash_register_closings.notes',
                'cash_register_closings.created_at',
                'users.name as closed_by_name',
            )
            ->whereBetween('cash_register_closings.created_at', [$from, $to])
            ->get()
            ->keyBy('cash_register_id');
    }

    /**
     * Registers named by either set, with their operator and opening balance.
     *
     * @param  Collection<int, string>  $ids
     * @return Collection<int, object>
     */
    private function registerContext(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('cash_registers')
            ->leftJoin('users', 'cash_registers.user_id', '=', 'users.id')
            ->select(
                'cash_registers.id',
                'cash_registers.user_id',
                'cash_registers.opened_at',
                'cash_registers.closed_at',
                'cash_registers.opening_balance',
                'users.name as operator_name',
            )
            ->whereIn('cash_registers.id', $ids->all())
            ->get();
    }

    /**
     * One row of the breakdown: what this drawer sold today and how it settled.
     *
     * @param  Collection<int, object>|null  $sales
     * @return array<string, mixed>
     */
    private function buildRegisterRow(
        object $register,
        ?Collection $sales,
        ?object $closing,
        int $investmentPct,
        int $profitPct,
    ): array {
        $sales ??= collect();

        $gross = round((float) $sales->sum('gross'), 2);
        $net = round((float) $sales->sum('net'), 2);

        $paymentMethods = $sales
            ->map(fn (object $row) => [
                'slug' => $row->payment_slug,
                'name' => $row->payment_name,
                'gross' => round((float) $row->gross, 2),
                'net' => round((float) $row->net, 2),
                'orders' => (int) $row->order_count,
            ])
            ->sortByDesc('gross')
            ->values()
            ->all();

        return [
            'cash_register_id' => $register->id,
            'operator' => $register->operator_name ?? '—',
            'operator_id' => $register->user_id,
            'opened_at' => $register->opened_at,
            'closed_at' => $register->closed_at,
            'opening_balance' => round((float) $register->opening_balance, 2),
            'is_closed' => $closing !== null || $register->closed_at !== null,
            'sales' => [
                'gross' => $gross,
                'net' => $net,
                'tax' => round((float) $sales->sum('tax'), 2),
                'discounts' => round((float) $sales->sum('discounts'), 2),
                'orders' => (int) $sales->sum('order_count'),
            ],
            // The split is computed per register on the NET subtotal, so the
            // rows add up to the day total instead of drifting by rounding.
            'split' => [
                'investment_fund' => round($net * ($investmentPct / 100), 2),
                'net_profit' => round($net * ($profitPct / 100), 2),
            ],
            'payment_methods' => $paymentMethods,
            'closing' => $closing === null ? null : $this->buildClosing($closing),
        ];
    }

    /**
     * The arqueo half of a row: expected versus declared, exactly as the
     * immutable ledger recorded it.
     *
     * @return array<string, mixed>
     */
    private function buildClosing(object $closing): array
    {
        $breakdown = json_decode((string) $closing->payment_breakdown, true);

        return [
            'id' => $closing->id,
            'closed_by' => $closing->closed_by_name ?? '—',
            'closed_at' => $closing->created_at,
            'expected_amount' => round((float) $closing->expected_amount, 2),
            'declared_amount' => round((float) $closing->declared_amount, 2),
            'difference_amount' => round((float) $closing->difference_amount, 2),
            'is_automated' => (bool) $closing->is_automated,
            'notes' => $closing->notes,
            'breakdown' => is_array($breakdown) ? $breakdown : [],
        ];
    }

    /**
     * Day totals, summed from the rows rather than requeried.
     *
     * Requerying would open the door to the panel's header disagreeing with the
     * rows beneath it — the single most corrosive thing a financial screen can
     * do to the trust of the person reading it.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function dayTotals(Collection $rows, int $investmentPct, int $profitPct): array
    {
        $gross = round((float) $rows->sum(fn (array $r) => $r['sales']['gross']), 2);
        $net = round((float) $rows->sum(fn (array $r) => $r['sales']['net']), 2);

        $closedNet = round((float) $rows->where('is_closed', true)->sum(fn (array $r) => $r['sales']['net']), 2);
        $openNet = round($net - $closedNet, 2);

        $withClosing = $rows->filter(fn (array $r) => $r['closing'] !== null);

        return [
            'gross' => $gross,
            'net' => $net,
            'tax' => round((float) $rows->sum(fn (array $r) => $r['sales']['tax']), 2),
            'discounts' => round((float) $rows->sum(fn (array $r) => $r['sales']['discounts']), 2),
            'orders' => (int) $rows->sum(fn (array $r) => $r['sales']['orders']),
            'investment_fund' => round($net * ($investmentPct / 100), 2),
            'net_profit' => round($net * ($profitPct / 100), 2),
            // The two states the day's money can be in. Their sum is `net`, and
            // showing them apart is what stops the panel from looking like the
            // business collapsed at any hour before the closings run.
            'settled_net' => $closedNet,
            'in_progress_net' => $openNet,
            // Arqueo aggregates over the closings performed today only.
            'expected_amount' => round((float) $withClosing->sum(fn (array $r) => $r['closing']['expected_amount']), 2),
            'declared_amount' => round((float) $withClosing->sum(fn (array $r) => $r['closing']['declared_amount']), 2),
            'difference_amount' => round((float) $withClosing->sum(fn (array $r) => $r['closing']['difference_amount']), 2),
        ];
    }

    /**
     * Day-wide distribution by payment method, folded from the register rows.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function dayPaymentMethods(Collection $rows): array
    {
        $folded = [];

        foreach ($rows as $row) {
            foreach ($row['payment_methods'] as $method) {
                $slug = $method['slug'];

                $folded[$slug] ??= [
                    'slug' => $slug,
                    'name' => $method['name'],
                    'gross' => 0.0,
                    'net' => 0.0,
                    'orders' => 0,
                ];

                $folded[$slug]['gross'] = round($folded[$slug]['gross'] + $method['gross'], 2);
                $folded[$slug]['net'] = round($folded[$slug]['net'] + $method['net'], 2);
                $folded[$slug]['orders'] += $method['orders'];
            }
        }

        return collect($folded)->sortByDesc('gross')->values()->all();
    }

    /**
     * Investment/profit split from global settings, with the historic defaults.
     *
     * @return array{0: int, 1: int}
     */
    private function readSplit(): array
    {
        $setting = GlobalSetting::where('key', 'investment_split')->first();

        return [
            (int) ($setting->value['investment_pct'] ?? 70),
            (int) ($setting->value['profit_pct'] ?? 30),
        ];
    }
}
