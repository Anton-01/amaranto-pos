<?php

namespace App\Support\Finance;

use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;

/**
 * The financial panel's filter criteria, resolved once and applied everywhere.
 *
 * WHY THIS IS A CLASS AND NOT FOUR COPIES OF `$request->filled(...)`. The panel
 * shows four things built from four different queries — the payment-method
 * chart, the 70/30 summary, the daily trend and the spreadsheet export — and
 * the operator expects all four to describe the SAME slice of the business. The
 * moment one endpoint forgets to honour `cash_register_id`, the export stops
 * matching the chart it was exported from, and the person auditing it has no
 * way to tell which of the two is lying. Resolving the criteria once and
 * handing the same object to every query is what makes that class of bug
 * impossible rather than unlikely.
 *
 * THE HONEST LIMIT, STATED OUT LOUD. A payment method is a property of a SALE.
 * Petty cash withdrawals and stock purchases have no payment method — they are
 * money leaving the business, not entering it. So when the operator filters by
 * "Efectivo", the deduction figures cannot be filtered the same way, and the
 * remainder of the investment fund stops being a coherent number. This class
 * does not pretend otherwise: `deductionsAreComparable()` reports the fact and
 * the API surfaces it, so the UI can warn instead of quietly showing a
 * remainder computed from an unfiltered numerator and a filtered denominator.
 */
final class FinanceFilters
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?string $paymentMethodId,
        public readonly ?string $userId,
        public readonly ?string $cashRegisterId,
    ) {}

    /**
     * Builds the criteria from a request, falling back to the caller's own
     * default window when no dates were sent.
     *
     * The boundaries are widened to full local days on purpose: an operator who
     * picks "3 de septiembre" means the whole day, and a `to` left at midnight
     * would silently drop every sale of that day.
     */
    public static function fromRequest(Request $request, Carbon $defaultFrom, ?Carbon $defaultTo = null): self
    {
        $from = $request->filled('date_from')
            ? Carbon::parse($request->string('date_from')->toString())->startOfDay()
            : $defaultFrom;

        $to = $request->filled('date_to')
            ? Carbon::parse($request->string('date_to')->toString())->endOfDay()
            : ($defaultTo ?? Carbon::now()->endOfDay());

        // A reversed range is a slip in the date picker, not an empty period.
        // Swapping is what the operator meant; returning zero rows would look
        // like the business had no sales.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return new self(
            $from,
            $to,
            $request->filled('payment_method_id') ? $request->string('payment_method_id')->toString() : null,
            $request->filled('user_id') ? $request->string('user_id')->toString() : null,
            $request->filled('cash_register_id') ? $request->string('cash_register_id')->toString() : null,
        );
    }

    /**
     * Applies the criteria to a query over `orders`.
     *
     * The operator filter reaches the orders table through the register that
     * produced them: an order has no owner of its own, its owner is whoever had
     * the drawer open.
     *
     * @param  string  $table  Alias the orders table carries in this query.
     */
    public function applyToOrders(EloquentBuilder|QueryBuilder $query, string $table = 'orders'): EloquentBuilder|QueryBuilder
    {
        $query->whereBetween("{$table}.created_at", [$this->from, $this->to]);

        if ($this->paymentMethodId !== null) {
            $query->where("{$table}.payment_method_id", $this->paymentMethodId);
        }

        if ($this->cashRegisterId !== null) {
            $query->where("{$table}.cash_register_id", $this->cashRegisterId);
        }

        if ($this->userId !== null) {
            $query->whereIn("{$table}.cash_register_id", function ($sub) {
                $sub->select('id')->from('cash_registers')->where('user_id', $this->userId);
            });
        }

        return $query;
    }

    /**
     * Applies the criteria to a deduction table (`petty_cash_transactions`,
     * `stock_movements`).
     *
     * Only the date window and the operator apply — those tables carry a
     * `user_id` of their own. The payment-method and register filters are
     * deliberately NOT applied: silently ignoring them would be dishonest, so
     * `deductionsAreComparable()` exists to say so instead.
     */
    public function applyToDeductions(EloquentBuilder|QueryBuilder $query, string $table): EloquentBuilder|QueryBuilder
    {
        $query->whereBetween("{$table}.created_at", [$this->from, $this->to]);

        if ($this->userId !== null) {
            $query->where("{$table}.user_id", $this->userId);
        }

        return $query;
    }

    /**
     * False when a filter is active that the deduction figures cannot honour,
     * which makes the investment-fund remainder incomparable with the fund it
     * is subtracted from.
     */
    public function deductionsAreComparable(): bool
    {
        return $this->paymentMethodId === null && $this->cashRegisterId === null;
    }

    public function hasAdvancedFilters(): bool
    {
        return $this->paymentMethodId !== null
            || $this->userId !== null
            || $this->cashRegisterId !== null;
    }

    /** SQL expression that buckets a timestamp into its LOCAL calendar day. */
    public static function localDateExpression(string $column): string
    {
        return "DATE({$column} AT TIME ZONE ".Timezone::sqlLiteral().')';
    }

    /**
     * Machine-readable echo of what was applied, so every response can state
     * the slice it describes and the UI never has to guess.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'user_id' => $this->userId,
            'cash_register_id' => $this->cashRegisterId,
            'has_advanced_filters' => $this->hasAdvancedFilters(),
            'deductions_comparable' => $this->deductionsAreComparable(),
        ];
    }

    /** Human label for the spreadsheet header and the PDF-style captions. */
    public function periodLabel(): string
    {
        return $this->from->format('d/m/Y').' — '.$this->to->format('d/m/Y');
    }
}
