import { useState, useEffect, useCallback } from 'react';
import { useLocation } from 'react-router-dom';
import { Dialog } from 'primereact/dialog';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import UserProfileDropdown from './UserProfileDropdown';
import NotificationBell from '../notifications/NotificationBell';
import useOnlineStatus from '../../hooks/useOnlineStatus';
import useRefreshOnVisible from '../../hooks/useRefreshOnVisible';
import { cachedGet, isStale } from '../../api/readCache';
import api from '../../api/axios';

const QUICK_STATS_KEY = 'header-today-sales';
const QUICK_STATS_TTL = 60000;

const pageNames = {
  '/dashboard': 'Dashboard',
  '/pos': 'Punto de Venta',
  '/ticket-config': 'Configuracion de Tickets',
  '/products': 'Productos',
  '/products/create': 'Nuevo Producto',
  '/categories': 'Categorías',
  '/promotions': 'Promociones',
  '/stock-movements': 'Almacén',
  '/petty-cash': 'Caja Chica',
  '/finance': 'Panel Financiero',
  '/admin/usuarios': 'Gestion de Usuarios',
  '/admin/ventas': 'Historial de Ventas',
  '/profile/notifications': 'Preferencias de Notificaciones',
  '/admin/papelera': 'Papelera Global',
  '/admin/metodos-pago': 'Métodos de Pago',
  '/admin/roles-permisos': 'Roles y Permisos',
  '/admin/configuracion': 'Configuracion del Sistema',
  '/admin/notificaciones/plantillas': 'Plantillas de Correo',
  '/profile': 'Mi Perfil',
  '/mesas': 'Plano de Mesas',
  '/admin/mesas': 'Catálogo de Mesas',
  '/admin/cierres': 'Cierres de Caja',
  '/admin/cash-closings-audit': 'Auditoría de Cierres',
};

const fmt = (v) => `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const paymentColors = {
  cash: 'bg-emerald-500',
  card: 'bg-indigo-500',
  transfer: 'bg-amber-500',
};

export default function AppHeader({ onToggleSidebar }) {
  const location = useLocation();
  const isOnline = useOnlineStatus();
  const [todaySales, setTodaySales] = useState(null);
  const [showSalesModal, setShowSalesModal] = useState(false);
  const [dailySummary, setDailySummary] = useState(null);
  const [summaryLoading, setSummaryLoading] = useState(false);

  const currentPage = pageNames[location.pathname] || '';

  /**
   * Contador de ventas del dia. Se lee al montar el header y al volver a la
   * pestana (si el dato ya vencio); nunca por temporizador.
   *
   * El `setInterval` de 60s que vivia aqui era, junto con el de la campana, el
   * origen del trafico ciclico: dos GET intercalados cada minuto en cualquier
   * ruta, indefinidamente y por cada pestana abierta. La cache de proceso
   * ademas evita que cada navegacion (que remonta el layout) lo vuelva a pedir.
   */
  const fetchQuickStats = useCallback(async ({ force = false } = {}) => {
    try {
      const today = new Date().toISOString().split('T')[0];
      const total = await cachedGet(
        QUICK_STATS_KEY,
        () => api
          .get('/orders', { params: { date_from: today, date_to: today, per_page: 1 } })
          .then((res) => res.data?.metadata?.total ?? null),
        { ttl: QUICK_STATS_TTL, force }
      );
      setTodaySales(total);
    } catch {
      // silently fail
    }
  }, []);

  useEffect(() => {
    fetchQuickStats();
  }, [fetchQuickStats]);

  useRefreshOnVisible(() => {
    if (isStale(QUICK_STATS_KEY, QUICK_STATS_TTL)) fetchQuickStats({ force: true });
  });

  const openSalesModal = async () => {
    setShowSalesModal(true);
    setSummaryLoading(true);
    try {
      const res = await api.get('/sales/daily-summary');
      setDailySummary(res.data.data);
    } catch {
      setDailySummary(null);
    } finally {
      setSummaryLoading(false);
    }
  };

  return (
    <>
      <header className="sticky top-0 z-40 flex h-16 items-center justify-between border-b border-slate-200 bg-white/80 px-4 backdrop-blur-md sm:px-6">
        <div className="flex items-center gap-3">
          <button
            onClick={onToggleSidebar}
            className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700"
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
          </button>

          <div className="hidden items-center gap-3 sm:flex">
            <h1 className="text-sm font-semibold text-slate-800">{currentPage}</h1>

            {!isOnline && (
              <>
                <div className="h-4 w-px bg-slate-200" />
                <div className="flex items-center gap-1.5 rounded-lg bg-amber-50 border border-amber-200 px-2.5 py-1">
                  <i className="pi pi-wifi text-amber-500 text-xs" />
                  <span className="text-[11px] font-semibold text-amber-500">
                    Operando en Modo Offline (Local)
                  </span>
                </div>
              </>
            )}

            {isOnline && todaySales !== null && (
              <>
                <div className="h-4 w-px bg-slate-200" />
                <div className="flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1">
                  <div className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  <span className="text-[11px] font-semibold text-emerald-700">
                    {todaySales} venta{todaySales !== 1 ? 's' : ''} hoy
                  </span>
                </div>
              </>
            )}
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={openSalesModal}
            className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-indigo-50 hover:text-indigo-600"
            title="Resumen del dia"
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />
            </svg>
          </button>

          <NotificationBell />

          <div className="mx-1 h-6 w-px bg-slate-200" />

          <UserProfileDropdown />
        </div>
      </header>

      <Dialog
        visible={showSalesModal}
        onHide={() => setShowSalesModal(false)}
        modal
        header={null}
        style={{ width: '50vw' }}
        className="w-full max-w-4xl"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <div className="p-6">
          <div className="mb-5 flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50">
              <svg className="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />
              </svg>
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">Resumen del Dia</h3>
              <p className="text-xs text-slate-500">{new Date().toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p>
            </div>
          </div>

          {summaryLoading ? (
            <div className="flex h-40 items-center justify-center">
              <div className="h-6 w-6 animate-spin rounded-full border-3 border-indigo-600 border-t-transparent" />
            </div>
          ) : dailySummary ? (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl bg-indigo-50 p-4">
                  <p className="text-[11px] font-medium text-indigo-600">Ingreso Bruto</p>
                  <p className="mt-1 text-xl font-bold text-indigo-900">{fmt(dailySummary.gross_income)}</p>
                </div>
                <div className="rounded-xl bg-emerald-50 p-4">
                  <p className="text-[11px] font-medium text-emerald-600">Ingreso Neto</p>
                  <p className="mt-1 text-xl font-bold text-emerald-900">{fmt(dailySummary.net_income)}</p>
                </div>
              </div>

              <div className="rounded-xl border border-slate-200 p-4">
                <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Desglose por Metodo</p>
                <div className="space-y-2">
                  {dailySummary.by_payment && typeof dailySummary.by_payment === 'object' &&
                    Object.entries(dailySummary.by_payment).map(([slug, data]) => (
                      <div key={slug} className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          <span className={`h-2 w-2 rounded-full ${paymentColors[slug] || 'bg-slate-400'}`} />
                          <span className="text-sm text-slate-700">{data.name || slug}</span>
                        </div>
                        <span className="text-sm font-semibold text-slate-900">{fmt(data.total)}</span>
                      </div>
                    ))
                  }
                </div>
              </div>

              <div className="flex items-center justify-between rounded-xl bg-rose-50 p-4">
                <div>
                  <p className="text-[11px] font-medium text-rose-600">Egresos Caja Chica</p>
                  <p className="mt-0.5 text-lg font-bold text-rose-900">-{fmt(dailySummary.petty_cash_total)}</p>
                </div>
                <div className="text-right">
                  <p className="text-[11px] font-medium text-slate-500">Ordenes</p>
                  <p className="mt-0.5 text-lg font-bold text-slate-900">{dailySummary.order_count}</p>
                </div>
              </div>

              {dailySummary.product_breakdown && dailySummary.product_breakdown.length > 0 && (
                <div className="rounded-xl border border-slate-200">
                  <div className="border-b border-slate-200 px-4 py-3">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Desglose de Ventas por Articulo</p>
                  </div>
                  <DataTable
                    value={dailySummary.product_breakdown}
                    size="small"
                    stripedRows
                    className="text-sm"
                    scrollable
                    scrollHeight="250px"
                    pt={{ wrapper: { className: 'rounded-b-xl' } }}
                  >
                    <Column field="product_name" header="Producto" className="text-sm" />
                    <Column
                      field="quantity_sold"
                      header="Piezas"
                      align="center"
                      style={{ width: '90px' }}
                      body={(row) => (
                        <span className="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-indigo-100 px-2 text-xs font-semibold text-indigo-700">
                          {row.quantity_sold}
                        </span>
                      )}
                    />
                    <Column
                      field="total_revenue"
                      header="Ingreso"
                      align="right"
                      style={{ width: '120px' }}
                      body={(row) => (
                        <span className="text-sm font-semibold text-slate-900">{fmt(row.total_revenue)}</span>
                      )}
                    />
                  </DataTable>
                </div>
              )}
            </div>
          ) : (
            <p className="py-8 text-center text-sm text-slate-400">No se pudieron cargar los datos.</p>
          )}
        </div>
      </Dialog>
    </>
  );
}
