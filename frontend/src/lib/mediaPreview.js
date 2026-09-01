/**
 * Preview engine of the media module — the client half.
 *
 * The backend already decides HOW a file should be rendered and ships that
 * decision as `preview_kind` on every row. This module turns that verdict into
 * pixels: which icon, which colour, which surface.
 *
 * Keeping the mapping here rather than inside the components is what lets the
 * grid tile, the list row, the detail modal and the share view show the same
 * file the same way. Six components each guessing from a MIME string is how a
 * spreadsheet ends up green in one view and grey in another.
 */

/**
 * Visual identity per preview kind.
 *
 * The colours follow the convention users already carry from their desktop —
 * green spreadsheets, red PDFs, blue documents — because a familiar colour is
 * read faster than any label.
 */
const KIND_STYLES = {
  image: {
    label: 'Imagen',
    accent: 'text-violet-600',
    surface: 'bg-violet-50',
    ring: 'ring-violet-100',
    // Picture frame with a mountain.
    icon: 'M2.25 15.75l5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
  },
  pdf: {
    label: 'PDF',
    accent: 'text-rose-600',
    surface: 'bg-rose-50',
    ring: 'ring-rose-100',
    // Document with a folded corner.
    icon: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
  },
  spreadsheet: {
    label: 'Hoja de cálculo',
    accent: 'text-emerald-600',
    surface: 'bg-emerald-50',
    ring: 'ring-emerald-100',
    // Grid of cells.
    icon: 'M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125M3.375 4.5h17.25M3.375 4.5c-.621 0-1.125.504-1.125 1.125M20.625 4.5c.621 0 1.125.504 1.125 1.125m-17.25 0h16.5m-16.5 0v1.5c0 .621.504 1.125 1.125 1.125M12 10.5h8.25m-8.25 0V8.25m0 2.25v2.25',
  },
  presentation: {
    label: 'Presentación',
    accent: 'text-amber-600',
    surface: 'bg-amber-50',
    ring: 'ring-amber-100',
    // Projector screen.
    icon: 'M3.375 3h17.25c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H3.375A1.125 1.125 0 0 1 2.25 13.875v-9.75C2.25 3.504 2.754 3 3.375 3ZM12 15v6m-3.75 0h7.5',
  },
  archive: {
    label: 'Comprimido',
    accent: 'text-slate-600',
    surface: 'bg-slate-100',
    ring: 'ring-slate-200',
    // Box.
    icon: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
  },
  document: {
    label: 'Documento',
    accent: 'text-sky-600',
    surface: 'bg-sky-50',
    ring: 'ring-sky-100',
    icon: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
  },
};

/** Visual identity for a file, always defined — unknown kinds fall back. */
export function previewStyle(file) {
  return KIND_STYLES[file?.preview_kind] ?? KIND_STYLES.document;
}

/**
 * Whether the tile should attempt to fetch and render the actual bytes.
 *
 * Only images. A PDF thumbnail would need a render pass the browser will not
 * do for free, and pulling a 10 MB spreadsheet to draw a green icon over it
 * would be pure waste on a grid of a hundred rows.
 */
export function rendersInline(file) {
  return file?.preview_kind === 'image';
}

/**
 * Whether the DETAIL view can embed the file directly.
 *
 * Wider than `rendersInline`: a modal shows one file at a time, so paying for
 * a PDF is reasonable there and unthinkable in the grid.
 */
export function embeddableInDetail(file) {
  return file?.preview_kind === 'image' || file?.preview_kind === 'pdf';
}

/** Spanish label of a visibility value, with "public" absent by design. */
export const VISIBILITY_LABELS = {
  private: 'Privado',
  restricted: 'Restringido',
};

/** Human status of a share link, aligned with the backend's `status`. */
export const SHARE_STATUS = {
  active: { label: 'Activo', severity: 'success' },
  expired: { label: 'Expirado', severity: 'warning' },
  revoked: { label: 'Revocado', severity: 'danger' },
  exhausted: { label: 'Agotado', severity: 'secondary' },
};

/** "1.4 MB" from a raw byte count, for the values the API does not preformat. */
export function humanBytes(bytes) {
  const value = Number(bytes) || 0;

  if (value >= 1073741824) return `${(value / 1073741824).toFixed(2)} GB`;
  if (value >= 1048576) return `${(value / 1048576).toFixed(2)} MB`;
  if (value >= 1024) return `${(value / 1024).toFixed(1)} KB`;

  return `${value} B`;
}

/**
 * Timestamp formatter shared by every media view.
 *
 * The API answers in ISO-8601 with an explicit offset, so `new Date` reads the
 * instant correctly and the browser renders it in the operator's own clock —
 * which is what an expiration or an audit line has to show to be trusted.
 */
export function formatDateTime(value) {
  if (!value) return '—';

  const date = new Date(value);

  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
}
