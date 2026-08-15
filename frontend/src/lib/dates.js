/**
 * Calendar dates for the API, always in the user's local timezone.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEVER use `toISOString()` to build a 'YYYY-MM-DD' string. Ever.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * `toISOString()` converts the instant to UTC before formatting it, so the
 * calendar day it prints is the UTC day, not the day on the user's wall
 * clock. In Mexico (UTC−6) those two stop agreeing every afternoon at 18:00:
 *
 *     Local wall clock            2026-08-14 18:30 (CST, UTC−6)
 *     new Date().toISOString()    "2026-08-15T00:30:00.000Z"
 *     .split('T')[0]              "2026-08-15"   ← tomorrow
 *
 * That is how the "Hoy" quick filter started asking the API for tomorrow's
 * sales after 6 PM and came back empty: the cashier's own tickets, minutes
 * old, had vanished from the screen. The API is not to blame — the backend
 * draws the day boundary in `America/Mexico_City` (CONTEXT.md section 53), so
 * it answered exactly the question it was asked. The question was wrong.
 *
 * The fix is to read the components the browser already resolved in local
 * time — `getFullYear()`, `getMonth()`, `getDate()` — and never let the value
 * pass through UTC at all. No conversion happens, so nothing can drift.
 *
 * Two things this module deliberately does NOT cover:
 *
 *  - **Instants.** A moment in time (`created_at`, `_queued_at`, a ticket's
 *    printed timestamp) is a point on the timeline, not a calendar date, and
 *    for those `toISOString()` is the correct tool: it is unambiguous and the
 *    receiver renders it back in local time. Only calendar dates belong here.
 *
 *  - **Parsing.** Turning an API string back into a Date is the inverse
 *    problem with its own trap (`new Date('2026-08-14')` is parsed as UTC
 *    midnight and can render as the 13th). Use `parseLocalYmd()` below rather
 *    than the bare constructor.
 */

/** Zero-pads a number to two digits, the width every date component needs. */
const pad = (value) => String(value).padStart(2, '0');

/**
 * Formats a Date as 'YYYY-MM-DD' using its LOCAL calendar components.
 *
 * This is the only function allowed to produce a date string bound for the
 * API's `date_from` / `date_to` parameters.
 *
 * @param {Date|null|undefined} date
 * @returns {string|null} The local calendar date, or null for a missing input.
 */
export function toLocalYmd(date) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return null;

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Today's calendar date on the user's clock, as 'YYYY-MM-DD'. */
export function todayYmd() {
  return toLocalYmd(new Date());
}

/**
 * Formats a Date as 'YYYY-MM-DD HH:mm:ss' using its LOCAL components.
 *
 * For the pickers that also capture a time (a promotion's validity window):
 * the wall clock the administrator chose has to reach the database unchanged.
 * Sending `toISOString()` instead would hand the server the same instant
 * expressed in UTC, and PHP — whose own timezone is already
 * `America/Mexico_City` — stores the shifted reading, so a promotion set to
 * begin at 00:00 quietly began at 06:00.
 *
 * @param {Date|null|undefined} date
 * @returns {string|null}
 */
export function toLocalDateTime(date) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return null;

  const time = `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;

  return `${toLocalYmd(date)} ${time}`;
}

/**
 * Parses a 'YYYY-MM-DD' string into a Date at LOCAL midnight.
 *
 * The mirror image of the bug this module exists for: `new Date('2026-08-14')`
 * is specified to parse a bare date as UTC midnight, which in Mexico renders
 * as 18:00 on the 13th. Building the Date from its parts keeps it on the day
 * the string names.
 *
 * @param {string|null|undefined} value
 * @returns {Date|null}
 */
export function parseLocalYmd(value) {
  if (typeof value !== 'string') return null;

  const [year, month, day] = value.split('-').map(Number);
  if (!year || !month || !day) return null;

  return new Date(year, month - 1, day);
}

/**
 * Returns a new Date moved by `days`, leaving the original untouched.
 *
 * Date arithmetic through `setDate()` is DST-safe — the browser normalizes
 * across month and year boundaries — but it mutates, and the quick filters
 * derive several ranges from the same "today".
 *
 * @param {Date} date
 * @param {number} days Negative values move into the past.
 * @returns {Date}
 */
export function addDays(date, days) {
  const shifted = new Date(date);
  shifted.setDate(shifted.getDate() + days);

  return shifted;
}

/**
 * Returns a new Date moved by `months`, clamped to the end of the target
 * month so that 31/Jan minus one month is 28/Feb and not 3/Mar.
 *
 * @param {Date} date
 * @param {number} months Negative values move into the past.
 * @returns {Date}
 */
export function addMonths(date, months) {
  const shifted = new Date(date);
  const targetDay = shifted.getDate();

  // Land on the 1st before shifting: setMonth() on a 31st overflows into the
  // following month whenever the target month is shorter.
  shifted.setDate(1);
  shifted.setMonth(shifted.getMonth() + months);

  const lastDayOfTargetMonth = new Date(shifted.getFullYear(), shifted.getMonth() + 1, 0).getDate();
  shifted.setDate(Math.min(targetDay, lastDayOfTargetMonth));

  return shifted;
}

/** First day of the month `date` falls in, at local midnight. */
export function startOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}
