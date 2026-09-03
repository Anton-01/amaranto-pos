import { useState, useEffect, useCallback } from 'react';
import { Tag } from 'primereact/tag';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, Cell,
} from 'recharts';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import CurrentDayBreakdown from '../../components/finance/CurrentDayBreakdown';
import FinancePeriodFilters from '../../components/finance/FinancePeriodFilters';
import { PERIOD_PRESETS, toQueryParams } from '../../components/finance/financePeriods';
import { parseLocalYmd } from '../../lib/dates';

/*
 * Conventional colours for the tenders every installation has, keyed by BOTH
 * the seeded English slugs and the Spanish ones the admin panel generates when
 * somebody adds a method by hand ("Efectivo" -> "efectivo"). Anything outside
 * this map falls back to the rotating palette below.
 *
 * The chart used to hard-code `efectivo`/`tarjeta`/`transferencia` as its three
 * bar keys, which meant it rendered nothing at all on a database seeded with
 * `cash`/`card`/`transfer` — and rendered nothing for a fourth method however
 * it was named. The series are now derived from the data.
 */
const paymentColors = {
  cash: '#22c55e',
  efectivo: '#22c55e',
  card: '#6366f1',
  tarjeta: '#6366f1',
  transfer: '#f59e0b',
  transferencia: '#f59e0b',
};

/** Deterministic fallback palette for methods the map above does not name. */
const fallbackPalette = ['#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#0ea5e9', '#84cc16'];

function colorFor(slug, index) {
  return paymentColors[slug] ?? fallbackPalette[index % fallbackPalette.length];
}

function fmt(n) {
  return parseFloat(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function CustomTooltip({ active, payload, label, labels = {} }) {
  if (!active || !payload?.length) return null;

  const total = payload.reduce((sum, p) => sum + (p.value || 0), 0);

  return (
    <div className="rounded-lg border border-slate-200 bg-white p-3 text-xs shadow-lg">
      <p className="mb-1 font-semibold text-slate-700">{label}</p>
      {payload.map((p, i) => (
        <div key={i} className="flex items-center gap-2">
          <span className="h-2 w-2 rounded-full" style={{ backgroundColor: p.color }} />
          <span className="text-slate-600">{labels[p.dataKey] || p.name}:</span>
          <span className="font-medium text-slate-900">${fmt(p.value)}</span>
        </div>
      ))}
      {payload.length > 1 && (
        <div className="mt-1 border-t border-slate-100 pt-1 font-semibold text-slate-800">
          Total: ${fmt(total)}
        </div>
      )}
    </div>
  );
}

export default function FinanceDashboardPage() {
  /*
   * One filter object drives every request on this page. Holding the period
   * and the advanced criteria together is what guarantees the chart, the
   * summary and the exported workbook describe the same slice — the same
   * reason the backend resolves them through a single FinanceFilters object.
   */
  const [filters, setFilters] = useState(() => ({
    range: PERIOD_PRESETS[0].range(),
    payment_method_id: null,
    user_id: null,
    cash_register_id: null,
  }));

  const [salesData, setSalesData] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [deductionsComparable, setDeductionsComparable] = useState(true);

  const fetchData = useCallback(async () => {
    if (!filters.range?.[0] || !filters.range?.[1]) return;

    setLoading(true);
    const params = toQueryParams(filters);

    try {
      const [salesRes, summaryRes] = await Promise.all([
        api.get('/analytics/sales-by-payment', { params }),
        api.get('/analytics/financial-summary', { params }),
      ]);
      setSalesData(salesRes.data.data);
      setSummary(summaryRes.data.data);
      // The server decides whether the remainder is comparable under the
      // active filters; the page only relays the verdict.
      setDeductionsComparable(summaryRes.data.metadata?.filters?.deductions_comparable ?? true);
    } catch {
      toast.error('Error al cargar datos financieros.');
    } finally {
      setLoading(false);
    }
  }, [filters]);

  useEffect(() => { fetchData(); }, [fetchData]);

  /**
   * Downloads the .xlsx audit workbook for the criteria currently on screen.
   *
   * The request goes through the authenticated axios client as a blob rather
   * than through a plain link: the endpoint requires a Bearer token, and a
   * `window.open` would issue an unauthenticated request that comes back 401.
   * The object URL is revoked right after the click so the workbook does not
   * stay pinned in memory for the life of the tab.
   */
  const handleExport = async () => {
    setExporting(true);
    try {
      const response = await api.get('/analytics/export', {
        params: toQueryParams(filters),
        responseType: 'blob',
      });

      const url = URL.createObjectURL(response.data);
      const anchor = document.createElement('a');
      anchor.href = url;

      // Honour the filename the server chose; it encodes the exported period.
      const disposition = response.headers['content-disposition'] ?? '';
      const match = disposition.match(/filename="?([^";]+)/);
      anchor.download = match ? match[1] : 'reporte-financiero.xlsx';

      anchor.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);

      toast.success('Reporte generado', { description: 'El archivo .xlsx se descargó con el detalle completo.' });
    } catch {
      toast.error('No se pudo generar el reporte', {
        description: 'Revisa el periodo seleccionado e intenta de nuevo.',
      });
    } finally {
      setExporting(false);
    }
  };

  /*
   * Series actually present in the payload, in a stable order. Deriving them
   * means a business that renames its tenders, or adds a fourth, gets a correct
   * chart with no code change — and the stack stops silently rendering empty
   * when the slugs do not match a hard-coded list.
   */
  const paymentSeries = (() => {
    const seen = new Map();

    for (const day of salesData) {
      for (const [slug, info] of Object.entries(day.methods ?? {})) {
        if (!seen.has(slug)) seen.set(slug, info.name ?? slug);
      }
    }

    return [...seen.entries()].map(([slug, name], index) => ({
      slug,
      name,
      color: colorFor(slug, index),
    }));
  })();

  const paymentLabels = Object.fromEntries(paymentSeries.map((s) => [s.slug, s.name]));

  const splitData = summary ? [
    {
      name: 'Ingreso Neto',
      value: summary.net_income,
      fill: '#6366f1',
    },
    {
      name: `Fondo Inv. ${summary.split.investment_pct}%`,
      value: summary.investment_fund,
      fill: '#3b82f6',
      remaining: summary.investment_remaining,
      deductions: summary.deductions.total,
    },
    {
      name: `Utilidad ${summary.split.profit_pct}%`,
      value: summary.net_profit,
      fill: '#22c55e',
    },
  ] : [];

  return (
    <AppLayout>
      <div className="mb-5 sm:mb-6">
        <h1 className="text-xl font-bold sm:text-2xl text-slate-900">Panel Financiero</h1>
        <p className="text-sm text-slate-500">Analisis de ingresos netos con segmentacion 70/30.</p>
      </div>

      {/* Opening view: today's money, before any period selection applies.
          It answers "where is the money right now", which is the only question
          that cannot wait for a date range to be chosen. */}
      <CurrentDayBreakdown />

      <FinancePeriodFilters
        value={filters}
        onChange={setFilters}
        onExport={handleExport}
        exporting={exporting}
        deductionsComparable={deductionsComparable}
      />

      {loading ? (
        <div className="flex h-64 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      ) : (
        <>
          {/* KPI Cards */}
          {summary && (
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div className="min-w-0 rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
                <p className="text-sm text-slate-500">Ingreso Bruto</p>
                <p className="mt-1 truncate text-xl font-bold tabular-nums text-slate-900 sm:text-2xl">${fmt(summary.gross_income)}</p>
                <p className="mt-0.5 text-xs text-slate-400">{summary.order_count} ordenes</p>
              </div>
              <div className="min-w-0 rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
                <p className="text-sm text-slate-500">Ingreso Neto (sin IVA)</p>
                <p className="mt-1 truncate text-xl font-bold tabular-nums text-indigo-600 sm:text-2xl">${fmt(summary.net_income)}</p>
                <p className="mt-0.5 truncate text-xs text-slate-400">IVA: ${fmt(summary.total_tax)} ({(summary.tax_rate * 100).toFixed(0)}%)</p>
                {summary.total_discounts > 0 && (
                  <p className="mt-0.5 text-xs font-medium text-amber-600">Descuentos: -${fmt(summary.total_discounts)}</p>
                )}
              </div>
              <div className="min-w-0 rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
                <p className="text-sm text-slate-500">Fondo Inversion ({summary.split.investment_pct}%)</p>
                <p className="mt-1 truncate text-xl font-bold tabular-nums text-blue-600 sm:text-2xl">${fmt(summary.investment_fund)}</p>
                <div className="mt-0.5 flex items-center gap-1">
                  <Tag
                    value={summary.investment_remaining >= 0 ? 'REMANENTE' : 'DEFICIT'}
                    severity={summary.investment_remaining >= 0 ? 'success' : 'danger'}
                    className="text-xs"
                  />
                  <span className={`text-xs font-semibold ${summary.investment_remaining >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                    ${fmt(Math.abs(summary.investment_remaining))}
                  </span>
                </div>
              </div>
              <div className="min-w-0 rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
                <p className="text-sm text-slate-500">Utilidad Real ({summary.split.profit_pct}%)</p>
                <p className="mt-1 truncate text-xl font-bold tabular-nums text-emerald-600 sm:text-2xl">${fmt(summary.net_profit)}</p>
                <p className="mt-0.5 text-xs text-slate-400">Ganancia neta del periodo</p>
              </div>
            </div>
          )}

          {/* Charts row */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* Vista 1: Stacked Bar Chart — Sales by Payment Method */}
            <div className="rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
              <h2 className="mb-1 text-base font-semibold text-slate-900">Ventas por Metodo de Pago</h2>
              <p className="mb-4 text-xs text-slate-500">Ingreso neto diario desglosado (sin IVA)</p>
              {salesData.length > 0 ? (
                <ResponsiveContainer width="100%" height={320}>
                  <BarChart data={salesData} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                    <XAxis
                      dataKey="date"
                      tick={{ fontSize: 11, fill: '#64748b' }}
                      tickFormatter={(v) => {
                        // `new Date('2026-08-14')` parses as UTC midnight and
                        // renders as the 13th here; parseLocalYmd builds the
                        // Date from its parts so the axis label matches the
                        // day the bar belongs to.
                        const d = parseLocalYmd(v);
                        return d ? d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short' }) : v;
                      }}
                    />
                    <YAxis tick={{ fontSize: 11, fill: '#64748b' }} tickFormatter={(v) => `$${v.toLocaleString()}`} />
                    <Tooltip content={<CustomTooltip labels={paymentLabels} />} />
                    <Legend
                      formatter={(value) => paymentLabels[value] || value}
                      wrapperStyle={{ fontSize: '12px' }}
                    />
                    {paymentSeries.map((series, index) => (
                      <Bar
                        key={series.slug}
                        dataKey={series.slug}
                        stackId="a"
                        fill={series.color}
                        // Only the topmost segment gets rounded corners, so the
                        // stack reads as one bar rather than a pile of pills.
                        radius={index === paymentSeries.length - 1 ? [4, 4, 0, 0] : [0, 0, 0, 0]}
                      />
                    ))}
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div className="flex h-64 items-center justify-center">
                  <p className="text-sm text-slate-400">Sin datos de ventas en el periodo seleccionado.</p>
                </div>
              )}
            </div>

            {/* Vista 2: 70/30 Split Comparison */}
            <div className="rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
              <h2 className="mb-1 text-base font-semibold text-slate-900">Segmentacion 70/30</h2>
              <p className="mb-4 text-xs text-slate-500">Ingreso Neto vs Fondo de Inversion vs Utilidad Real</p>
              {summary && summary.net_income > 0 ? (
                <>
                  <ResponsiveContainer width="100%" height={220}>
                    <BarChart data={splitData} layout="vertical" margin={{ top: 5, right: 30, left: 20, bottom: 5 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
                      <XAxis type="number" tick={{ fontSize: 11, fill: '#64748b' }} tickFormatter={(v) => `$${v.toLocaleString()}`} />
                      <YAxis type="category" dataKey="name" tick={{ fontSize: 11, fill: '#64748b' }} width={120} />
                      <Tooltip
                        formatter={(value) => [`$${fmt(value)}`, '']}
                        contentStyle={{ fontSize: '12px', borderRadius: '8px' }}
                      />
                      <Bar dataKey="value" radius={[0, 6, 6, 0]}>
                        {splitData.map((entry, index) => (
                          <Cell key={index} fill={entry.fill} />
                        ))}
                      </Bar>
                    </BarChart>
                  </ResponsiveContainer>

                  {/* Deductions breakdown */}
                  <div className="mt-4 rounded-lg bg-slate-50 p-4">
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                      Deducciones del Fondo de Inversion ({summary.split.investment_pct}%)
                    </h3>
                    <div className="space-y-1.5 text-sm">
                      <div className="flex justify-between">
                        <span className="text-slate-600">Fondo bruto ({summary.split.investment_pct}%)</span>
                        <span className="font-medium text-slate-900">${fmt(summary.investment_fund)}</span>
                      </div>
                      <div className="flex justify-between text-rose-600">
                        <span>(-) Caja chica</span>
                        <span>-${fmt(summary.deductions.petty_cash)}</span>
                      </div>
                      <div className="flex justify-between text-rose-600">
                        <span>(-) Compras de stock</span>
                        <span>-${fmt(summary.deductions.stock_purchases)}</span>
                      </div>
                      <div className="flex justify-between border-t border-slate-200 pt-1.5">
                        <span className="font-semibold text-slate-700">Remanente</span>
                        <span className={`font-bold ${summary.investment_remaining >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                          ${fmt(summary.investment_remaining)}
                        </span>
                      </div>
                    </div>

                    {summary.deductions.merma_losses > 0 && (
                      <div className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        Perdidas por merma en el periodo: ${fmt(summary.deductions.merma_losses)}
                      </div>
                    )}
                  </div>
                </>
              ) : (
                <div className="flex h-64 items-center justify-center">
                  <p className="text-sm text-slate-400">Sin ingresos en el periodo seleccionado.</p>
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </AppLayout>
  );
}
