/**
 * Shared mobile-first primitives for dialogs and dense data views.
 *
 * The system uses PrimeReact, whose `Dialog` was historically sized with an
 * inline `style={{ width: '50vw' }}`. Inline styles are invisible to media
 * queries, so a "half the screen" dialog stayed half the screen on a 360px
 * phone — 180px of usable width. Every dialog now takes its width from these
 * class strings instead, which lets the same declaration describe all three
 * form factors.
 *
 * The scale is deliberately coarse. Four sizes cover every dialog in the
 * system, and having a fixed set is what keeps the modals feeling like one
 * family rather than twenty independent decisions.
 */

const DIALOG_WIDTHS = {
  // Confirmations and short prompts.
  sm: 'w-[calc(100vw-1.5rem)] max-w-md sm:w-[26rem]',
  // Single-column forms — the default for most CRUD dialogs.
  md: 'w-[calc(100vw-1.5rem)] max-w-lg sm:w-[32rem]',
  // Two-column forms and small tables.
  lg: 'w-[calc(100vw-1.5rem)] max-w-3xl sm:w-[90vw] lg:w-[48rem]',
  // Detail panels and embedded tables.
  xl: 'w-[calc(100vw-1.5rem)] max-w-5xl sm:w-[92vw] lg:w-[64rem]',
};

/**
 * Width classes for a `<Dialog>`.
 *
 * On phones every size resolves to "the viewport minus a 12px gutter on each
 * side", which is the widest a dialog can be while still reading as a floating
 * surface rather than a broken full-screen page.
 *
 * @param {'sm'|'md'|'lg'|'xl'} size
 * @returns {string} Tailwind classes for the Dialog's `className`.
 */
export function dialogClass(size = 'md') {
  return DIALOG_WIDTHS[size] ?? DIALOG_WIDTHS.md;
}

/**
 * Passthrough options shared by every dialog: a padded mask so the dialog can
 * never touch the screen edge, a height cap that keeps the header and footer
 * reachable on a short landscape phone, and a scrollable content area.
 *
 * Spread it into `pt` and add per-dialog keys after it.
 */
export const DIALOG_PT = {
  mask: { className: 'p-3 sm:p-4' },
  root: { className: 'max-h-[92dvh] !max-w-full' },
  content: { className: 'overflow-y-auto overscroll-contain' },
};

/**
 * Column visibility helpers for PrimeReact `<Column className=...>`.
 *
 * PrimeReact owns the `<th>`/`<td>` elements, so Tailwind's `hidden
 * md:table-cell` cannot be attached to them from the outside. `className` on a
 * Column is forwarded to both cells, and these classes (defined in index.css)
 * implement exactly that rule for the three breakpoints we use.
 *
 * Usage: `<Column className={HIDE_BELOW.md} ... />` on any secondary column.
 */
export const HIDE_BELOW = {
  sm: 'col-hide-sm',
  md: 'col-hide-md',
  lg: 'col-hide-lg',
};

/**
 * Standard wrapper for a table that keeps its table shape on phones and scrolls
 * horizontally inside its own box.
 *
 * `pos-table` applies the minimum column widths and the touch-scroll behaviour
 * from index.css; `pos-table-wide` raises the minimum for tables with more than
 * roughly six columns.
 */
export const TABLE_CLASS = 'pos-table';
export const TABLE_CLASS_WIDE = 'pos-table pos-table-wide';
