import { useCallback, useEffect, useState } from 'react';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';

const money = (n) =>
  `$${parseFloat(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const clock = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
};

const methodColors = {
  cash: 'bg-emerald-500',
  card: 'bg-indigo-500',
  transfer: 'bg-amber-500',
};

/**
 * Money flow of the business day in progress, register by register.
 *
 * WHY THIS SITS AT THE TOP OF THE PANEL. Everything below it answers questions
 * about periods that are already over. This answers the only question an
 * operator has while the shop is open: where is today's money right now, and
 * does each drawer add up.
 *
 * THE DISTINCTION THE WHOLE COMPONENT IS BUILT AROUND. Until the drawers close,
 * the day's money is in two states — settled into an immutable arqueo, and
 * still live inside an open register. The header shows both apart rather than
 * one blended figure, because a manager reading "$4,000" needs to know which
 * part has been counted and which part is still a promise.
 */
export default function CurrentDayBreakdown() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState(null);

  const fetchBreakdown = useCallback(async ({ silent = false } = {}) => {
    if (!silent) setLoading(true);
    try {
      const res = await api.get('/analytics/current-day');
      setData(res.data.data);
    } catch {
      toast.error('No se pudo cargar el desglose del día.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchBreakdown(); }, [fetchBreakdown]);

  if (loading) {
    return (
      <div className="mb-6 animate-pulse rounded-xl bg-white p-5 ring-1 ring-slate-200">
        <div className="h-5 w-56 rounded bg-slate-100" />
        <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => <div key={i} className="h-20 rounded-lg bg-slate-100" />)}
        </div>
      </div>
    );
  }

  if (!data) return null;

  const { totals, split, payment_methods: methods, registers, counters } = data;
  const hasMovement = totals.orders > 0;

  return (
    <section className="mb-6 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <header className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-base font-semibold text-slate-900">Desglose del Día en Curso</h2>
            <Tag value={data.business_date} severity="info" className="text-[10px]" />
          </div>
          <p className="mt-0.5 text-xs text-slate-500">
            {counters.registers === 0
              ? 'Sin actividad de caja registrada hoy.'
              : `${counters.registers} caja(s): ${counters.open} abierta(s), ${counters.closed} cerrada(s).`}
          </p>
        </div>
        <Button
          icon="pi pi-refresh"
          label="Actualizar"
          size="small"
          outlined
          onClick={() => fetchBreakdown({ silent: true })}
          className="w-full sm:w-auto"
        />
      </header>

      {!hasMovement ? (
        <div className="px-5 py-10 text-center">
          <i className="pi pi-inbox mb-2 text-2xl text-slate-300" />
          <p className="text-sm text-slate-400">Aún no hay ventas registradas hoy.</p>
        </div>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 p-4 sm:p-5 lg:grid-cols-4">
            <div className="min-w-0 rounded-lg bg-slate-50 p-3">
              <p className="text-xs text-slate-500">Ingreso Bruto</p>
              <p className="mt-0.5 truncate text-lg font-bold tabular-nums text-slate-900">{money(totals.gross)}</p>
              <p className="text-[11px] text-slate-400">{totals.orders} órdenes · IVA {money(totals.tax)}</p>
            </div>
            <div className="min-w-0 rounded-lg bg-indigo-50 p-3">
              <p className="text-xs text-indigo-700">Ingreso Neto</p>
              <p className="mt-0.5 truncate text-lg font-bold tabular-nums text-indigo-700">{money(totals.net)}</p>
              <p className="text-[11px] text-indigo-500">Base del reparto {split.investment_pct}/{split.profit_pct}</p>
            </div>
            <div className="min-w-0 rounded-lg bg-blue-50 p-3">
              <p className="text-xs text-blue-700">Fondo Inversión {split.investment_pct}%</p>
              <p className="mt-0.5 truncate text-lg font-bold tabular-nums text-blue-700">{money(totals.investment_fund)}</p>
            </div>
            <div className="min-w-0 rounded-lg bg-emerald-50 p-3">
              <p className="text-xs text-emerald-700">Utilidad {split.profit_pct}%</p>
              <p className="mt-0.5 truncate text-lg font-bold tabular-nums text-emerald-700">{money(totals.net_profit)}</p>
            </div>
          </div>

          {/* The two states of today's money. Blending them into one figure is
              what makes a panel look like the business collapsed at any hour
              before the closings run. */}
          <div className="grid gap-3 px-4 pb-4 sm:grid-cols-2 sm:px-5 sm:pb-5">
            <div className="rounded-lg border border-slate-200 p-3">
              <div className="flex items-center justify-between">
                <span className="flex items-center gap-1.5 text-xs font-medium text-slate-600">
                  <i className="pi pi-lock text-[11px] text-slate-400" /> Ya liquidado en arqueo
                </span>
                <span className="text-sm font-bold tabular-nums text-slate-900">{money(totals.settled_net)}</span>
              </div>
              {counters.closed > 0 && (
                <div className="mt-2 space-y-1 border-t border-slate-100 pt-2 text-[11px]">
                  <div className="flex justify-between text-slate-500">
                    <span>Esperado</span><span className="tabular-nums">{money(totals.expected_amount)}</span>
                  </div>
                  <div className="flex justify-between text-slate-500">
                    <span>Declarado</span><span className="tabular-nums">{money(totals.declared_amount)}</span>
                  </div>
                  <div className={`flex justify-between font-semibold ${
                    totals.difference_amount < 0 ? 'text-rose-600' : totals.difference_amount > 0 ? 'text-emerald-600' : 'text-slate-600'
                  }`}>
                    <span>Diferencia</span><span className="tabular-nums">{money(totals.difference_amount)}</span>
                  </div>
                </div>
              )}
            </div>

            <div className="rounded-lg border border-amber-200 bg-amber-50/50 p-3">
              <div className="flex items-center justify-between">
                <span className="flex items-center gap-1.5 text-xs font-medium text-amber-800">
                  <i className="pi pi-clock text-[11px]" /> En curso (cajas abiertas)
                </span>
                <span className="text-sm font-bold tabular-nums text-amber-800">{money(totals.in_progress_net)}</span>
              </div>
              <p className="mt-2 border-t border-amber-200 pt-2 text-[11px] text-amber-700">
                {counters.open === 0
                  ? 'Todas las cajas del día están cerradas.'
                  : `${counters.open} caja(s) sin arquear. Este monto aún no ha sido contado físicamente.`}
              </p>
            </div>
          </div>

          {methods.length > 0 && (
            <div className="border-t border-slate-100 px-4 py-4 sm:px-5">
              <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                Distribución por método de pago
              </p>
              <div className="space-y-2">
                {methods.map((m) => {
                  const pct = totals.gross > 0 ? (m.gross / totals.gross) * 100 : 0;
                  return (
                    <div key={m.slug}>
                      <div className="flex items-center justify-between text-xs">
                        <span className="text-slate-600">{m.name} <span className="text-slate-400">({m.orders})</span></span>
                        <span className="font-medium tabular-nums text-slate-900">
                          {money(m.gross)} <span className="text-slate-400">{pct.toFixed(1)}%</span>
                        </span>
                      </div>
                      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div
                          className={`h-full rounded-full ${methodColors[m.slug] ?? 'bg-slate-400'}`}
                          style={{ width: `${pct}%` }}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Per-register detail: the "distribución exacta entre cada cierre"
              the panel exists for. Collapsed by default — a floor with six
              drawers would otherwise bury everything below it. */}
          <div className="border-t border-slate-100">
            <p className="px-4 pt-4 text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:px-5">
              Detalle por caja
            </p>
            <ul className="divide-y divide-slate-50 p-2 sm:p-3">
              {registers.map((r) => {
                const isOpen = expanded === r.cash_register_id;
                return (
                  <li key={r.cash_register_id} className="rounded-lg">
                    <button
                      type="button"
                      onClick={() => setExpanded(isOpen ? null : r.cash_register_id)}
                      aria-expanded={isOpen}
                      className="flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-3 text-left transition-colors hover:bg-slate-50"
                    >
                      <span className={`h-2 w-2 shrink-0 rounded-full ${r.is_closed ? 'bg-slate-300' : 'bg-emerald-500'}`} />
                      <span className="min-w-0 flex-1">
                        <span className="flex flex-wrap items-center gap-2">
                          <span className="truncate text-sm font-medium text-slate-900">{r.operator}</span>
                          <Tag
                            value={r.is_closed ? 'Cerrada' : 'Abierta'}
                            severity={r.is_closed ? 'secondary' : 'success'}
                            className="text-[10px]"
                          />
                          {r.closing?.is_automated && (
                            <Tag value="Automático" severity="warning" className="text-[10px]" />
                          )}
                        </span>
                        <span className="mt-0.5 block text-[11px] text-slate-400">
                          {clock(r.opened_at)} → {r.is_closed ? clock(r.closing?.closed_at ?? r.closed_at) : 'en curso'}
                          {' · '}{r.sales.orders} órdenes
                        </span>
                      </span>
                      <span className="shrink-0 text-right">
                        <span className="block text-sm font-bold tabular-nums text-slate-900">{money(r.sales.gross)}</span>
                        <span className="block text-[11px] text-slate-400">neto {money(r.sales.net)}</span>
                      </span>
                      <i className={`pi ${isOpen ? 'pi-chevron-up' : 'pi-chevron-down'} shrink-0 text-xs text-slate-400`} />
                    </button>

                    {isOpen && (
                      <div className="space-y-3 rounded-lg bg-slate-50 p-3 text-xs">
                        <div className="grid gap-3 sm:grid-cols-2">
                          <div>
                            <p className="mb-1 font-semibold text-slate-600">Métodos de pago</p>
                            {r.payment_methods.length === 0 ? (
                              <p className="text-slate-400">Sin ventas.</p>
                            ) : r.payment_methods.map((m) => (
                              <div key={m.slug} className="flex justify-between py-0.5">
                                <span className="text-slate-600">{m.name}</span>
                                <span className="tabular-nums font-medium text-slate-900">{money(m.gross)}</span>
                              </div>
                            ))}
                          </div>
                          <div>
                            <p className="mb-1 font-semibold text-slate-600">
                              Reparto {split.investment_pct}/{split.profit_pct} de esta caja
                            </p>
                            <div className="flex justify-between py-0.5">
                              <span className="text-slate-600">Fondo inversión</span>
                              <span className="tabular-nums font-medium text-blue-700">{money(r.split.investment_fund)}</span>
                            </div>
                            <div className="flex justify-between py-0.5">
                              <span className="text-slate-600">Utilidad</span>
                              <span className="tabular-nums font-medium text-emerald-700">{money(r.split.net_profit)}</span>
                            </div>
                            <div className="flex justify-between py-0.5">
                              <span className="text-slate-600">Fondo inicial</span>
                              <span className="tabular-nums text-slate-700">{money(r.opening_balance)}</span>
                            </div>
                          </div>
                        </div>

                        {r.closing ? (
                          <div className="rounded-md border border-slate-200 bg-white p-3">
                            <p className="mb-2 font-semibold text-slate-600">
                              Arqueo · cerró {r.closing.closed_by}
                            </p>
                            <table className="w-full">
                              <thead>
                                <tr className="text-[10px] uppercase tracking-wide text-slate-400">
                                  <th className="pb-1 text-left font-medium">Método</th>
                                  <th className="pb-1 text-right font-medium">Esperado</th>
                                  <th className="pb-1 text-right font-medium">Declarado</th>
                                  <th className="pb-1 text-right font-medium">Dif.</th>
                                </tr>
                              </thead>
                              <tbody>
                                {r.closing.breakdown.map((b) => (
                                  <tr key={b.payment_method_id ?? b.slug} className="border-t border-slate-100">
                                    <td className="py-1 text-slate-600">{b.name}</td>
                                    <td className="py-1 text-right tabular-nums text-slate-700">{money(b.expected)}</td>
                                    <td className="py-1 text-right tabular-nums text-slate-700">{money(b.declared)}</td>
                                    <td className={`py-1 text-right tabular-nums font-medium ${
                                      b.difference < 0 ? 'text-rose-600' : b.difference > 0 ? 'text-emerald-600' : 'text-slate-400'
                                    }`}>{money(b.difference)}</td>
                                  </tr>
                                ))}
                                <tr className="border-t-2 border-slate-200 font-semibold">
                                  <td className="py-1 text-slate-700">Total del arqueo</td>
                                  <td className="py-1 text-right tabular-nums text-slate-900">{money(r.closing.expected_amount)}</td>
                                  <td className="py-1 text-right tabular-nums text-slate-900">{money(r.closing.declared_amount)}</td>
                                  <td className={`py-1 text-right tabular-nums ${
                                    r.closing.difference_amount < 0 ? 'text-rose-600' : r.closing.difference_amount > 0 ? 'text-emerald-600' : 'text-slate-500'
                                  }`}>{money(r.closing.difference_amount)}</td>
                                </tr>
                              </tbody>
                            </table>
                            {r.closing.notes && (
                              <p className="mt-2 border-t border-slate-100 pt-2 text-[11px] italic text-slate-500">
                                {r.closing.notes}
                              </p>
                            )}
                          </div>
                        ) : (
                          <p className="rounded-md border border-amber-200 bg-amber-50 p-2 text-[11px] text-amber-700">
                            Caja abierta: el dinero aún no ha sido arqueado, por lo que no hay comparación
                            entre lo esperado y lo declarado.
                          </p>
                        )}
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          </div>
        </>
      )}
    </section>
  );
}
