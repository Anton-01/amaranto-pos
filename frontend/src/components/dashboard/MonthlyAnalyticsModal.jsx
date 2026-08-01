import { useState, useEffect, useCallback } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  PieChart, Pie, Cell, Legend, LineChart, Line,
} from 'recharts';
import api from '../../api/axios';

const PIE_COLORS = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#0ea5e9'];

const fmt = (v) => `$${Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

/** Chip de comparativa contra el mes anterior: verde sube, rojo baja. */
function DeltaChip({ value }) {
  if (value == null) return <span className="text-[11px] text-slate-400">sin mes previo</span>;
  const up = value >= 0;
  return (
    <span className={`inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[11px] font-semibold ${up ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
      <i className={`pi ${up ? 'pi-arrow-up-right' : 'pi-arrow-down-right'} text-[9px]`} />
      {Math.abs(value)}% vs mes previo
    </span>
  );
}

/** Esqueleto visual mientras el backend agrega el mes completo. */
function AnalyticsSkeleton() {
  return (
    <div className="space-y-4" aria-busy="true">
      <div className="flex items-center justify-center gap-3 py-2">
        <div className="h-6 w-6 animate-spin rounded-full border-3 border-indigo-600 border-t-transparent" />
        <span className="text-sm font-medium text-slate-500">Procesando métricas del mes...</span>
      </div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[...Array(4)].map((_, i) => (
          <div key={i} className="animate-pulse rounded-xl bg-slate-100 p-4">
            <div className="h-3 w-20 rounded bg-slate-200" />
            <div className="mt-3 h-6 w-28 rounded bg-slate-200" />
            <div className="mt-2 h-3 w-16 rounded bg-slate-200" />
          </div>
        ))}
      </div>
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <div className="h-64 animate-pulse rounded-xl bg-slate-100" />
        <div className="h-64 animate-pulse rounded-xl bg-slate-100" />
      </div>
      <div className="h-48 animate-pulse rounded-xl bg-slate-100" />
    </div>
  );
}

/**
 * Modal de Analitica Financiera Mensual (admin/manager).
 *
 * Una sola llamada al endpoint agregado trae todo el mes; el estado de carga
 * combina spinner + esqueleto para latencias con volumen alto. El export CSV
 * se genera client-side desde los mismos datos ya mostrados: lo que exportas
 * es exactamente lo que ves.
 */
export default function MonthlyAnalyticsModal({ visible, onHide }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [month, setMonth] = useState('');

  const fetchAnalytics = useCallback(async (targetMonth) => {
    setLoading(true);
    try {
      const res = await api.get('/dashboard/monthly-analytics', {
        params: targetMonth ? { month: targetMonth } : {},
      });
      setData(res.data.data);
      setMonth(res.data.data.month);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al cargar la analítica financiera.');
      setData(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (visible) fetchAnalytics(null);
  }, [visible, fetchAnalytics]);

  const shiftMonth = (delta) => {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    fetchAnalytics(next);
  };

  const isCurrentMonth = () => {
    const now = new Date();
    return month === `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  };

  const exportCsv = () => {
    if (!data) return;
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [];

    lines.push(['CRONOS POS - REPORTE FINANCIERO MENSUAL', data.month_label].map(esc).join(','));
    lines.push('');
    lines.push(['METRICA', 'VALOR', 'VS MES PREVIO (%)'].map(esc).join(','));
    lines.push([esc('Ventas Totales'), data.totals.total_sales, data.comparison.sales_delta_pct ?? 'N/D'].join(','));
    lines.push([esc('Ingreso Neto (sin IVA)'), data.totals.net_sales, ''].join(','));
    lines.push([esc('IVA Recaudado'), data.totals.tax_total, ''].join(','));
    lines.push([esc('Descuentos Otorgados'), data.totals.discount_total, ''].join(','));
    lines.push([esc('Ordenes'), data.totals.order_count, data.comparison.orders_delta_pct ?? 'N/D'].join(','));
    lines.push([esc('Ticket Promedio'), data.totals.avg_ticket, data.comparison.avg_ticket_delta_pct ?? 'N/D'].join(','));
    lines.push('');
    lines.push([esc('DISTRIBUCION POR METODO DE PAGO')].join(','));
    lines.push(['Metodo', 'Ordenes', 'Total'].map(esc).join(','));
    data.by_payment_method.forEach(pm => lines.push([esc(pm.name), pm.order_count, pm.total].join(',')));
    lines.push('');
    lines.push([esc('TOP PRODUCTOS')].join(','));
    lines.push(['Producto', 'Piezas', 'Ingreso'].map(esc).join(','));
    data.top_products.forEach(p => lines.push([esc(p.name), p.quantity_sold, p.revenue].join(',')));
    lines.push('');
    lines.push([esc('VENTAS POR HORA (HORAS PICO)')].join(','));
    lines.push(['Hora', 'Ordenes', 'Total'].map(esc).join(','));
    data.peak_hours.filter(h => h.orders > 0).forEach(h => lines.push([esc(h.hour), h.orders, h.total].join(',')));

    // BOM para que Excel abra el CSV en UTF-8 sin romper acentos.
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `analitica-financiera-${data.month}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success('Reporte CSV descargado.');
  };

  const kpis = data ? [
    { label: 'Ventas Totales', value: fmt(data.totals.total_sales), delta: data.comparison.sales_delta_pct, bg: 'bg-indigo-50', text: 'text-indigo-900', tag: 'text-indigo-600' },
    { label: 'Órdenes', value: data.totals.order_count, delta: data.comparison.orders_delta_pct, bg: 'bg-emerald-50', text: 'text-emerald-900', tag: 'text-emerald-600' },
    { label: 'Ticket Promedio', value: fmt(data.totals.avg_ticket), delta: data.comparison.avg_ticket_delta_pct, bg: 'bg-amber-50', text: 'text-amber-900', tag: 'text-amber-600' },
    { label: 'Descuentos', value: fmt(data.totals.discount_total), delta: null, bg: 'bg-rose-50', text: 'text-rose-900', tag: 'text-rose-600' },
  ] : [];

  return (
    <Dialog
      visible={visible}
      onHide={onHide}
      modal
      header={null}
      className="w-full max-w-5xl"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30' },
        root: { className: 'rounded-2xl border-0 shadow-2xl' },
        content: { className: 'p-0' },
      }}
    >
      <div className="p-6">
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50">
              <i className="pi pi-chart-line text-lg text-indigo-600" />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">Analítica Financiera</h3>
              <p className="text-xs text-slate-500">{data?.month_label ?? 'Cargando periodo...'}</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Button
              icon="pi pi-chevron-left"
              onClick={() => shiftMonth(-1)}
              disabled={loading || !month}
              tooltip="Mes anterior"
              tooltipOptions={{ position: 'bottom' }}
              className="cursor-pointer h-9 w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              icon="pi pi-chevron-right"
              onClick={() => shiftMonth(1)}
              disabled={loading || !month || isCurrentMonth()}
              tooltip="Mes siguiente"
              tooltipOptions={{ position: 'bottom' }}
              className="cursor-pointer h-9 w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              label="Exportar Reporte"
              icon="pi pi-download"
              onClick={exportCsv}
              disabled={loading || !data}
              className="cursor-pointer rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </div>

        {loading ? (
          <AnalyticsSkeleton />
        ) : !data ? (
          <p className="py-16 text-center text-sm text-slate-400">No se pudieron cargar las métricas.</p>
        ) : (
          <div className="space-y-4">
            {/* KPIs con comparativa */}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              {kpis.map((kpi) => (
                <div key={kpi.label} className={`rounded-xl p-4 ${kpi.bg}`}>
                  <p className={`text-[11px] font-medium ${kpi.tag}`}>{kpi.label}</p>
                  <p className={`mt-1 text-xl font-bold ${kpi.text}`}>{kpi.value}</p>
                  <div className="mt-1.5"><DeltaChip value={kpi.delta} /></div>
                </div>
              ))}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              {/* Distribucion por metodo de pago */}
              <div className="rounded-xl border border-slate-200 p-4">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                  Distribución por Método de Pago
                </p>
                {data.by_payment_method.length === 0 ? (
                  <p className="py-16 text-center text-sm text-slate-400">Sin ventas en el periodo.</p>
                ) : (
                  <ResponsiveContainer width="100%" height={230}>
                    <PieChart>
                      <Pie
                        data={data.by_payment_method}
                        dataKey="total"
                        nameKey="name"
                        innerRadius={50}
                        outerRadius={80}
                        paddingAngle={2}
                      >
                        {data.by_payment_method.map((_, i) => (
                          <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip formatter={(v) => fmt(v)} />
                      <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                    </PieChart>
                  </ResponsiveContainer>
                )}
              </div>

              {/* Horas pico */}
              <div className="rounded-xl border border-slate-200 p-4">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                  Horas Pico de Venta
                </p>
                <ResponsiveContainer width="100%" height={230}>
                  <BarChart data={data.peak_hours} margin={{ top: 5, right: 5, left: -20, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                    <XAxis dataKey="hour" tick={{ fontSize: 10 }} interval={2} />
                    <YAxis tick={{ fontSize: 10 }} />
                    <Tooltip formatter={(v, name) => name === 'total' ? fmt(v) : v} />
                    <Bar dataKey="orders" name="Órdenes" fill="#6366f1" radius={[3, 3, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>

            {/* Tendencia diaria del mes */}
            <div className="rounded-xl border border-slate-200 p-4">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                Tendencia Diaria de Ingresos
              </p>
              <ResponsiveContainer width="100%" height={180}>
                <LineChart data={data.daily_trend} margin={{ top: 5, right: 5, left: -10, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                  <XAxis dataKey="day" tick={{ fontSize: 10 }} tickFormatter={(d) => d?.slice(8)} />
                  <YAxis tick={{ fontSize: 10 }} tickFormatter={(v) => `$${(v / 1000).toFixed(0)}k`} />
                  <Tooltip formatter={(v, name) => name === 'total' ? fmt(v) : v} labelFormatter={(d) => `Día ${d}`} />
                  <Line type="monotone" dataKey="total" name="total" stroke="#6366f1" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            </div>

            {/* Top productos */}
            <div className="rounded-xl border border-slate-200">
              <div className="border-b border-slate-200 px-4 py-3">
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-400">
                  Top 10 Productos por Ingreso
                </p>
              </div>
              {data.top_products.length === 0 ? (
                <p className="py-8 text-center text-sm text-slate-400">Sin ventas en el periodo.</p>
              ) : (
                <table className="w-full text-sm">
                  <tbody>
                    {data.top_products.map((p, i) => (
                      <tr key={i} className="border-b border-slate-100 last:border-0">
                        <td className="w-10 px-4 py-2 text-center">
                          <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700">
                            {i + 1}
                          </span>
                        </td>
                        <td className="px-2 py-2 text-slate-900">{p.name}</td>
                        <td className="px-2 py-2 text-right text-xs text-slate-500">{p.quantity_sold} pzas</td>
                        <td className="px-4 py-2 text-right font-semibold text-slate-900">{fmt(p.revenue)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        )}
      </div>
    </Dialog>
  );
}
