import { useState, useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import UserProfileDropdown from './UserProfileDropdown';
import api from '../../api/axios';

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
  '/profile/notifications': 'Preferencias de Notificaciones',
  '/admin/papelera': 'Papelera Global',
};

export default function AppHeader({ collapsed, onToggleSidebar }) {
  const location = useLocation();
  const [todaySales, setTodaySales] = useState(null);
  const [notificationCount] = useState(0);

  const currentPage = pageNames[location.pathname] || '';

  useEffect(() => {
    const fetchQuickStats = async () => {
      try {
        const today = new Date().toISOString().split('T')[0];
        const res = await api.get('/orders', { params: { date_from: today, date_to: today, per_page: 1 } });
        setTodaySales(res.data?.meta?.total ?? null);
      } catch {
        // silently fail
      }
    };
    fetchQuickStats();
    const interval = setInterval(fetchQuickStats, 60000);
    return () => clearInterval(interval);
  }, []);

  return (
    <header className="sticky top-0 z-40 flex h-16 items-center justify-between border-b border-slate-200 bg-white/80 px-4 backdrop-blur-md sm:px-6">
      <div className="flex items-center gap-3">
        <button
          onClick={onToggleSidebar}
          className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700"
        >
          <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
          </svg>
        </button>

        <div className="hidden items-center gap-3 sm:flex">
          <h1 className="text-sm font-semibold text-slate-800">{currentPage}</h1>

          {todaySales !== null && (
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
          className="relative flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700"
          title="Notificaciones"
        >
          <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
          </svg>
          {notificationCount > 0 && (
            <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">
              {notificationCount > 9 ? '9+' : notificationCount}
            </span>
          )}
        </button>

        <div className="mx-1 h-6 w-px bg-slate-200" />

        <UserProfileDropdown />
      </div>
    </header>
  );
}
