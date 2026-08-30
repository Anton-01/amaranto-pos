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
import { todayYmd } from '../../lib/dates';
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
  '/admin/jobs-monitor': 'Monitor de Jobs y Respaldos',
};

const fmt = (v) => `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const paymentColors = {
  cash: 'bg-emerald-500',
  card: 'bg-indigo-500',
  transfer: 'bg-amber-500',
};

export default function AppHeader({ collapsed, onToggleSidebar, onOpenMobileNav }) {
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
      // Local calendar day, never the UTC one: after 18:00 CST the two differ
      // and the counter would report tomorrow's (empty) sales. See lib/dates.
      const today = todayYmd();
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
      <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-2 border-b border-slate-200 bg-white/80 px-3 backdrop-blur-md sm:px-4 lg:px-6">
        <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
          {/*
            The two form factors need two different actions from the same
            glyph, so they get two buttons rather than one button branching on
            a JS media query: below `lg` the hamburger opens the off-canvas
            drawer, from `lg` up it collapses the docked rail. Visibility is
            pure CSS, which means no hydration flash and no resize listener.
          */}
          <button
            onClick={onOpenMobileNav}
            aria-label="Abrir menu"
            aria-controls="app-sidebar"
            className="flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 lg:hidden"
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
          </button>

          <button
            onClick={onToggleSidebar}
            aria-label={collapsed ? 'Expandir menu lateral' : 'Colapsar menu lateral'}
            className="hidden h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 lg:flex"
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
          </button>

          <div className="flex min-w-0 flex-1 items-center gap-3">
            {/* The title now survives on phones — truncated, never wrapped, so
                a long page name cannot push the header taller or wider. */}
            <h1 className="truncate text-sm font-semibold text-slate-800">{currentPage}</h1>

            {!isOnline && (
              <>
                <div className="hidden h-4 w-px bg-slate-200 sm:block" />
                {/* Compact dot on phones, full sentence from sm up. */}
                <div className="flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1 sm:px-2.5">
                  <i className="pi pi-wifi text-xs text-amber-500" />
                  <span className="hidden text-[11px] font-semibold text-amber-500 sm:inline">
                    Operando en Modo Offline (Local)
                  </span>
                </div>
              </>
            )}

            {isOnline && todaySales !== null && (
              <>
                <div className="hidden h-4 w-px bg-slate-200 md:block" />
                <div className="hidden shrink-0 items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 md:flex">
                  <div className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  <span className="text-[11px] font-semibold text-emerald-700">
                    {todaySales} venta{todaySales !== 1 ? 's' : ''} hoy
                  </span>
                </div>
              </>
            )}
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-1 sm:gap-2">
          <button
            onClick={openSalesModal}
            className="flex h-10 w-10 cursor-pointer items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-indigo-50 hover:text-indigo-600 sm:h-9 sm:w-9"
            title="Resumen del dia"
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />
            </svg>
          </button>

          <NotificationBell />

          <div className="mx-1 hidden h-6 w-px bg-slate-200 sm:block" />

          <UserProfileDropdown />
        </div>
      </header>

      <Dialog
        visible={showSalesModal}
        onHide={() => setShowSalesModal(false)}
        modal
        header={null}
        dismissableMask
        className="w-[calc(100vw-1.5rem)] max-w-4xl sm:w-[90vw] lg:w-[50vw]"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30 p-3 sm:p-4' },
          root: { className: 'rounded-2xl border-0 shadow-2xl max-h-[90vh]' },
          content: { className: 'p-0 overflow-y-auto' },
        }}
      >
        <div className="p-4 sm:p-6">
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
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="min-w-0 rounded-xl bg-indigo-50 p-3 sm:p-4">
                  <p className="text-[11px] font-medium text-indigo-600">Ingreso Bruto</p>
                  <p className="mt-1 truncate text-lg font-bold text-indigo-900 sm:text-xl">{fmt(dailySummary.gross_income)}</p>
                </div>
                <div className="min-w-0 rounded-xl bg-emerald-50 p-3 sm:p-4">
                  <p className="text-[11px] font-medium text-emerald-600">Ingreso Neto</p>
                  <p className="mt-1 truncate text-lg font-bold text-emerald-900 sm:text-xl">{fmt(dailySummary.net_income)}</p>
                </div>
              </div>

              <div className="rounded-xl border border-slate-200 p-4">
                <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Desglose por Metodo</p>
                <div className="space-y-2">
                  {dailySummary.by_payment && typeof dailySummary.by_payment === 'object' &&
                    Object.entries(dailySummary.by_payment).map(([slug, data]) => (
                      <div key={slug} className="flex items-center justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-2">
                          <span className={`h-2 w-2 shrink-0 rounded-full ${paymentColors[slug] || 'bg-slate-400'}`} />
                          <span className="truncate text-sm text-slate-700">{data.name || slug}</span>
                        </div>
                        <span className="shrink-0 text-sm font-semibold text-slate-900">{fmt(data.total)}</span>
                      </div>
                    ))
                  }
                </div>
              </div>

              <div className="flex items-center justify-between gap-3 rounded-xl bg-rose-50 p-3 sm:p-4">
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
                  <div className="border-b border-slate-200 px-3 py-3 sm:px-4">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Desglose de Ventas por Articulo</p>
                  </div>
                  <DataTable
                    value={dailySummary.product_breakdown}
                    size="small"
                    stripedRows
                    className="pos-table text-sm"
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
