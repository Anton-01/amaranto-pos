/**
 * Plantillas de detalle por tipo de notificacion.
 *
 * Cada componente recibe el `data` JSON inmutable de la notificacion y lo
 * renderiza. Este archivo exporta unicamente componentes (fast-refresh).
 */

const fmt = (v) => `$${Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const fmtDateTime = (iso) =>
  iso ? new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

/** Plantilla de detalle: cierre automatico de cajas (job de las 21:00). */
export function AutoCashClosingDetail({ data }) {
  const closings = data?.closings ?? [];
  // Consolidado de la corrida: lo que vendieron todas las cajas cerradas a las
  // 21:00, ya sumado por el comando. Ausente en notificaciones anteriores al
  // desglose, y su JSON es inmutable — por eso se comprueba, no se reconstruye.
  const products = data?.products ?? [];

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div className="rounded-xl bg-indigo-50 p-3 text-center">
          <p className="text-[11px] font-medium text-indigo-600">Cajas Cerradas</p>
          <p className="mt-1 truncate text-lg font-bold tabular-nums text-indigo-900 sm:text-xl">{data?.registers_closed ?? 0}</p>
        </div>
        <div className="rounded-xl bg-emerald-50 p-3 text-center">
          <p className="text-[11px] font-medium text-emerald-600">Total Esperado</p>
          <p className="mt-1 truncate text-lg font-bold tabular-nums text-emerald-900 sm:text-xl">{fmt(data?.total_expected)}</p>
        </div>
        <div className={`rounded-xl p-3 text-center ${data?.registers_failed > 0 ? 'bg-rose-50' : 'bg-slate-50'}`}>
          <p className={`text-[11px] font-medium ${data?.registers_failed > 0 ? 'text-rose-600' : 'text-slate-500'}`}>Fallidas</p>
          <p className={`mt-1 text-xl font-bold ${data?.registers_failed > 0 ? 'text-rose-900' : 'text-slate-700'}`}>
            {data?.registers_failed ?? 0}
          </p>
        </div>
      </div>

      <div className="rounded-lg border border-slate-200 overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50">
              <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Caja</th>
              <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Operador</th>
              <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Esperado</th>
              <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Diferencia</th>
              <th className="px-3 py-2 text-center text-xs font-semibold text-slate-600">Rezago</th>
            </tr>
          </thead>
          <tbody>
            {closings.map((c) => (
              <tr key={c.closing_id} className="border-b border-slate-100 last:border-0">
                <td className="px-3 py-2 font-mono text-xs text-slate-700">{c.register_folio}</td>
                <td className="px-3 py-2 text-slate-900">{c.operator_name}</td>
                <td className="px-3 py-2 text-right font-semibold text-slate-900">{fmt(c.expected_amount)}</td>
                <td className={`px-3 py-2 text-right font-semibold ${c.difference_amount < 0 ? 'text-rose-600' : c.difference_amount > 0 ? 'text-emerald-600' : 'text-slate-500'}`}>
                  {fmt(c.difference_amount)}
                </td>
                <td className="px-3 py-2 text-center">
                  {c.was_stale ? (
                    <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">DIA PREVIO</span>
                  ) : (
                    <span className="text-xs text-slate-400">—</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {products.length > 0 && (
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
            Productos vendidos en la jornada
          </p>
          <div className="overflow-hidden rounded-lg border border-slate-200">
            <div className="max-h-64 overflow-y-auto">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-slate-50">
                  <tr className="border-b border-slate-200">
                    <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Producto</th>
                    <th className="px-3 py-2 text-center text-xs font-semibold text-slate-600">Piezas</th>
                    <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Ingreso</th>
                    <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Costo</th>
                  </tr>
                </thead>
                <tbody>
                  {products.map((p, i) => (
                    <tr key={p.product_id ?? `deleted-${i}`} className="border-b border-slate-100 last:border-0">
                      <td className="px-3 py-2 text-slate-900">{p.name}</td>
                      <td className="px-3 py-2 text-center tabular-nums text-slate-700">{p.quantity_sold}</td>
                      <td className="px-3 py-2 text-right font-medium tabular-nums text-slate-900">{fmt(p.revenue)}</td>
                      <td className="px-3 py-2 text-right tabular-nums text-slate-600">{fmt(p.cost)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                    <td className="px-3 py-2 text-slate-700">{products.length} producto(s)</td>
                    <td className="px-3 py-2 text-center tabular-nums text-slate-900">{data?.total_pieces_sold ?? 0}</td>
                    <td className="px-3 py-2" />
                    <td className="px-3 py-2 text-right tabular-nums text-slate-900">{fmt(data?.total_products_cost)}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>
      )}

      <div className="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5 text-xs text-amber-800">
        Los montos declarados de un cierre automatico no fueron verificados fisicamente:
        el sistema asume declarado = esperado. Concilia el efectivo al abrir el siguiente turno.
      </div>

      <p className="text-right text-xs text-slate-400">
        Ejecutado por {data?.executed_by ?? 'Sistema'} · {fmtDateTime(data?.executed_at)}
      </p>
    </div>
  );
}

/** Plantilla generica para tipos aun no registrados: key-value legible. */
export function GenericDetail({ data }) {
  const entries = Object.entries(data ?? {});

  if (entries.length === 0) {
    return <p className="py-6 text-center text-sm text-slate-400">Esta notificacion no incluye datos adicionales.</p>;
  }

  return (
    <div className="rounded-lg border border-slate-200 divide-y divide-slate-100">
      {entries.map(([key, value]) => (
        <div key={key} className="flex items-start justify-between gap-4 px-3 py-2 text-sm">
          <span className="font-mono text-xs text-slate-500">{key}</span>
          <span className="text-right font-medium text-slate-900 break-all">
            {typeof value === 'object' ? JSON.stringify(value) : String(value)}
          </span>
        </div>
      ))}
    </div>
  );
}
