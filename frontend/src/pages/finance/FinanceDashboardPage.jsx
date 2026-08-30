import { useState, useEffect, useCallback } from 'react';
import { Calendar } from 'primereact/calendar';
import { Tag } from 'primereact/tag';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, Cell,
} from 'recharts';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import { addDays, parseLocalYmd, toLocalYmd } from '../../lib/dates';

const paymentColors = {
  efectivo: '#22c55e',
  tarjeta: '#6366f1',
  transferencia: '#f59e0b',
};

const paymentLabels = {
  efectivo: 'Efectivo',
  tarjeta: 'Tarjeta',
  transferencia: 'Transferencia',
};

const reasonLabels = {
  expired: 'Caducado',
  damaged_spilled: 'Dañado/Derramado',
  internal_consumption: 'Consumo Interno',
  theft_loss: 'Robo/Extravío',
};

function fmt(n) {
  return parseFloat(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function CustomTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-lg text-xs">
      <p className="mb-1 font-semibold text-slate-700">{label}</p>
      {payload.map((p, i) => (
        <div key={i} className="flex items-center gap-2">
          <span className="h-2 w-2 rounded-full" style={{ backgroundColor: p.color }} />
          <span className="text-slate-600">{paymentLabels[p.dataKey] || p.name}:</span>
          <span className="font-medium text-slate-900">${fmt(p.value)}</span>
        </div>
      ))}
    </div>
  );
}

export default function FinanceDashboardPage() {
  const today = new Date();

  const [dateRange, setDateRange] = useState([addDays(today, -6), today]);
  const [salesData, setSalesData] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);

  const fetchData = useCallback(async () => {
    if (!dateRange[0] || !dateRange[1]) return;
    setLoading(true);
    // Local calendar days: this page used to carry its own copy of the
    // formatter, which is how the rule drifts. It now shares lib/dates with
    // the rest of the app.
    const from = toLocalYmd(dateRange[0]);
    const to = toLocalYmd(dateRange[1]);

    try {
      const [salesRes, summaryRes] = await Promise.all([
        api.get('/analytics/sales-by-payment', { params: { date_from: from, date_to: to + ' 23:59:59' } }),
        api.get('/analytics/financial-summary', { params: { date_from: from, date_to: to + ' 23:59:59' } }),
      ]);
      setSalesData(salesRes.data.data);
      setSummary(summaryRes.data.data);
    } catch {
      toast.error('Error al cargar datos financieros.');
    } finally {
      setLoading(false);
    }
  }, [dateRange]);

  useEffect(() => { fetchData(); }, [fetchData]);

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
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Panel Financiero</h1>
          <p className="text-sm text-slate-500">Analisis de ingresos netos con segmentacion 70/30.</p>
        </div>
        <div className="flex items-center gap-2">
          <Calendar
            value={dateRange}
            onChange={(e) => setDateRange(e.value)}
            selectionMode="range"
            readOnlyInput
            dateFormat="dd/mm/yy"
            placeholder="Seleccionar periodo"
            className="text-sm"
            pt={{ input: { root: { className: 'rounded-lg border-slate-200 px-3 py-2 text-sm w-56' } } }}
          />
        </div>
      </div>

      {loading ? (
        <div className="flex h-64 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      ) : (
        <>
          {/* KPI Cards */}
          {summary && (
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div className="rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
                <p className="text-sm text-slate-500">Ingreso Bruto</p>
                <p className="mt-1 text-2xl font-bold text-slate-900">${fmt(summary.gross_income)}</p>
                <p className="mt-0.5 text-xs text-slate-400">{summary.order_count} ordenes</p>
              </div>
              <div className="rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
                <p className="text-sm text-slate-500">Ingreso Neto (sin IVA)</p>
                <p className="mt-1 text-2xl font-bold text-indigo-600">${fmt(summary.net_income)}</p>
                <p className="mt-0.5 text-xs text-slate-400">IVA: ${fmt(summary.total_tax)} ({(summary.tax_rate * 100).toFixed(0)}%)</p>
                {summary.total_discounts > 0 && (
                  <p className="mt-0.5 text-xs font-medium text-amber-600">Descuentos: -${fmt(summary.total_discounts)}</p>
                )}
              </div>
              <div className="rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
                <p className="text-sm text-slate-500">Fondo Inversion ({summary.split.investment_pct}%)</p>
                <p className="mt-1 text-2xl font-bold text-blue-600">${fmt(summary.investment_fund)}</p>
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
              <div className="rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
                <p className="text-sm text-slate-500">Utilidad Real ({summary.split.profit_pct}%)</p>
                <p className="mt-1 text-2xl font-bold text-emerald-600">${fmt(summary.net_profit)}</p>
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
                    <Tooltip content={<CustomTooltip />} />
                    <Legend
                      formatter={(value) => paymentLabels[value] || value}
                      wrapperStyle={{ fontSize: '12px' }}
                    />
                    <Bar dataKey="efectivo" stackId="a" fill={paymentColors.efectivo} radius={[0, 0, 0, 0]} />
                    <Bar dataKey="tarjeta" stackId="a" fill={paymentColors.tarjeta} radius={[0, 0, 0, 0]} />
                    <Bar dataKey="transferencia" stackId="a" fill={paymentColors.transferencia} radius={[4, 4, 0, 0]} />
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
