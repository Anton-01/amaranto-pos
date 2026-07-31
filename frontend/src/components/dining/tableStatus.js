/**
 * Lenguaje visual unico del comedor.
 *
 * El color es la informacion principal del plano: un mesero debe leer el estado
 * del salon de un vistazo, sin detenerse a leer etiquetas.
 */
export const TABLE_STATUS = {
  available: {
    label: 'Disponible',
    severity: 'success',
    dot: 'bg-emerald-500',
    card: 'border-emerald-200 bg-emerald-50/60 hover:border-emerald-400 hover:bg-emerald-50',
    accent: 'text-emerald-700',
    badge: 'bg-emerald-100 text-emerald-700',
  },
  occupied: {
    label: 'Ocupada',
    severity: 'danger',
    dot: 'bg-rose-500',
    card: 'border-rose-200 bg-rose-50/60 hover:border-rose-400 hover:bg-rose-50',
    accent: 'text-rose-700',
    badge: 'bg-rose-100 text-rose-700',
  },
  reserved: {
    label: 'Reservada',
    severity: 'warning',
    dot: 'bg-amber-500',
    card: 'border-amber-200 bg-amber-50/60 hover:border-amber-400 hover:bg-amber-50',
    accent: 'text-amber-700',
    badge: 'bg-amber-100 text-amber-700',
  },
};

export const tableStatusMeta = (status) => TABLE_STATUS[status] ?? TABLE_STATUS.available;

export const fmtCurrency = (value) =>
  `$${parseFloat(value ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** "1h 25m" / "40m" — legible de un vistazo en el plano. */
export const fmtElapsed = (minutes) => {
  const mins = Math.max(0, parseInt(minutes ?? 0, 10));
  if (mins < 60) return `${mins}m`;
  return `${Math.floor(mins / 60)}h ${mins % 60}m`;
};
