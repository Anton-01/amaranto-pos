import { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../context/AuthContext';
import AppLayout from '../components/layout/AppLayout';
import MonthlyAnalyticsModal from '../components/dashboard/MonthlyAnalyticsModal';
import DashboardSkeleton from '../components/dashboard/DashboardSkeleton';
import { toast } from 'sonner';
import api from '../api/axios';
import {
  LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  PieChart, Pie, Cell, Legend,
} from 'recharts';

const PIE_COLORS = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6'];

const fmt = (v) => `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

export default function DashboardPage() {
  const { user } = useAuth();
  const [stats, setStats] = useState(null);
  const [hourly, setHourly] = useState([]);
  const [topProducts, setTopProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showAnalytics, setShowAnalytics] = useState(false);

  const canSeeAnalytics = user?.roles?.some(r => ['admin', 'manager'].includes(r));

  const fetchDashboard = useCallback(async () => {
    setLoading(true);
    try {
      const [statsRes, hourlyRes, topRes] = await Promise.all([
        api.get('/dashboard/stats'),
        api.get('/dashboard/hourly-trend'),
        api.get('/dashboard/top-products'),
      ]);
      setStats(statsRes.data.data);
      setHourly(hourlyRes.data.data);
      setTopProducts(topRes.data.data);
    } catch {
      toast.error('Error al cargar el dashboard.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchDashboard(); }, [fetchDashboard]);

  const kpis = stats ? [
    { label: 'Ventas del Mes', value: fmt(stats.monthly_sales), icon: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z', color: 'indigo' },
    { label: 'Ordenes Hoy', value: stats.today_orders, icon: 'M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z', color: 'emerald' },
    { label: 'Ticket Promedio', value: fmt(stats.avg_ticket), icon: 'M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z', color: 'amber' },
    { label: 'Alertas de Stock', value: stats.low_stock_alerts, icon: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z', color: stats.low_stock_alerts > 0 ? 'rose' : 'slate' },
  ] : [];

  const colorMap = {
    indigo: { bg: 'bg-indigo-50', icon: 'text-indigo-600', ring: 'ring-indigo-100' },
    emerald: { bg: 'bg-emerald-50', icon: 'text-emerald-600', ring: 'ring-emerald-100' },
    amber: { bg: 'bg-amber-50', icon: 'text-amber-600', ring: 'ring-amber-100' },
    rose: { bg: 'bg-rose-50', icon: 'text-rose-600', ring: 'ring-rose-100' },
    slate: { bg: 'bg-slate-50', icon: 'text-slate-500', ring: 'ring-slate-100' },
  };

  /*
   * Perceived performance of the first paint.
   *
   * The skeleton stands in only while there is nothing to draw yet. On a
   * refetch `stats` still holds the previous payload, so the page keeps
   * showing real numbers instead of collapsing back into grey boxes — which
   * would look like a regression, not like loading.
   */
  if (loading && !stats) {
    return (
      <AppLayout>
        <div role="status" aria-busy="true" aria-label="Cargando el dashboard">
          <DashboardSkeleton />
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <div className="mb-5 flex flex-col gap-3 sm:mb-6 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
        <div>
          <h1 className="text-xl font-bold sm:text-2xl text-slate-900">Dashboard</h1>
          <p className="text-sm text-slate-500">
            Bienvenido, {user?.name}. Resumen ejecutivo del negocio.
          </p>
        </div>

        {/* Analitica financiera completa: exclusiva de admin/manager, el
            backend la protege con role:admin,manager ademas de este gate. */}
        {canSeeAnalytics && (
          <button
            onClick={() => setShowAnalytics(true)}
            className="group flex w-full cursor-pointer items-center justify-center gap-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3 text-sm font-semibold text-white shadow-md shadow-indigo-200 transition-all hover:shadow-lg hover:shadow-indigo-300 sm:w-auto sm:py-2.5"
          >
            <i className="pi pi-chart-line" />
            Analítica Financiera
            <span className="rounded bg-white/20 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide">
              Mensual
            </span>
          </button>
        )}
      </div>

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {kpis.map((kpi) => {
          const c = colorMap[kpi.color];
          return (
            <div key={kpi.label} className={`min-w-0 rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ${c.ring}`}>
              <div className="flex items-center gap-3">
                <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${c.bg}`}>
                  <svg className={`h-5 w-5 ${c.icon}`} fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d={kpi.icon} />
                  </svg>
                </div>
                <div className="min-w-0">
                  <p className="truncate text-xs font-medium text-slate-500">{kpi.label}</p>
                  <p className="truncate text-lg font-bold tabular-nums text-slate-900 sm:text-xl">{kpi.value}</p>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div className="rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200 xl:col-span-2">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">Tendencia de Ventas por Hora (Hoy)</h2>
          {hourly.some(h => h.total > 0) ? (
            <ResponsiveContainer width="100%" height={220} className="sm:!h-[280px]">
              <LineChart data={hourly} margin={{ top: 5, right: 20, bottom: 5, left: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                <XAxis dataKey="hour" tick={{ fontSize: 11 }} stroke="#94a3b8" interval={2} />
                <YAxis tick={{ fontSize: 11 }} stroke="#94a3b8" tickFormatter={(v) => `$${v}`} />
                <Tooltip
                  formatter={(v) => [fmt(v), 'Ingresos']}
                  contentStyle={{ borderRadius: 12, border: '1px solid #e2e8f0', fontSize: 13 }}
                />
                <Line type="monotone" dataKey="total" stroke="#6366f1" strokeWidth={2.5} dot={false} activeDot={{ r: 5 }} />
              </LineChart>
            </ResponsiveContainer>
          ) : (
            <div className="flex h-64 items-center justify-center text-sm text-slate-400">
              Sin ventas registradas hoy
            </div>
          )}
        </div>

        <div className="rounded-xl bg-white p-4 shadow-sm sm:p-5 ring-1 ring-slate-200">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">Top 5 Productos (Mes)</h2>
          {topProducts.length > 0 ? (
            <>
              <ResponsiveContainer width="100%" height={200}>
                <PieChart>
                  <Pie
                    data={topProducts}
                    dataKey="total_qty"
                    nameKey="name"
                    cx="50%"
                    cy="50%"
                    innerRadius={45}
                    outerRadius={75}
                    paddingAngle={3}
                  >
                    {topProducts.map((_, i) => (
                      <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip formatter={(v) => [`${v} uds`, 'Cantidad']} contentStyle={{ borderRadius: 12, fontSize: 13 }} />
                </PieChart>
              </ResponsiveContainer>
              <div className="mt-2 space-y-1.5">
                {topProducts.map((p, i) => (
                  <div key={i} className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                      <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: PIE_COLORS[i % PIE_COLORS.length] }} />
                      <span className="truncate text-slate-700">{p.name}</span>
                    </div>
                    <span className="font-semibold text-slate-900">{p.total_qty} uds</span>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <div className="flex h-64 items-center justify-center text-sm text-slate-400">
              Sin datos de ventas este mes
            </div>
          )}
        </div>
      </div>
      <MonthlyAnalyticsModal visible={showAnalytics} onHide={() => setShowAnalytics(false)} />

    </AppLayout>
  );
}
