import { toLocalYmd, startOfMonth, addDays } from '../../lib/dates';

/**
 * Period presets offered above the calendar.
 *
 * The three the operator actually reaches for, and nothing else. A preset list
 * that tries to cover every window ends up slower to scan than the date picker
 * it was meant to replace.
 *
 * Each entry returns a fresh [from, to] pair rather than a stored constant,
 * because "mes en curso" means something different tomorrow.
 */
export const PERIOD_PRESETS = [
  {
    key: 'week',
    label: 'Última semana',
    // Seven days INCLUDING today: -6 plus today. Using -7 would silently make
    // every "week" an eight-day window.
    range: () => [addDays(new Date(), -6), new Date()],
  },
  {
    key: 'month',
    label: 'Mes en curso',
    range: () => [startOfMonth(new Date()), new Date()],
  },
  {
    key: 'year',
    label: 'Año actual',
    range: () => [new Date(new Date().getFullYear(), 0, 1), new Date()],
  },
];

/** True when a [from, to] pair matches what a preset would produce today. */
export function matchesPreset(range, preset) {
  if (!range?.[0] || !range?.[1]) return false;

  const [from, to] = preset.range();

  return toLocalYmd(range[0]) === toLocalYmd(from) && toLocalYmd(range[1]) === toLocalYmd(to);
}

/** Query params for the analytics endpoints, from the filter state. */
export function toQueryParams(value) {
  const [from, to] = value.range ?? [];

  return {
    date_from: from ? toLocalYmd(from) : undefined,
    date_to: to ? toLocalYmd(to) : undefined,
    payment_method_id: value.payment_method_id || undefined,
    user_id: value.user_id || undefined,
    cash_register_id: value.cash_register_id || undefined,
  };
}
