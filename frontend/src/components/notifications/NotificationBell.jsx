import { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { OverlayPanel } from 'primereact/overlaypanel';
import api from '../../api/axios';
import { cachedGet, isStale } from '../../api/readCache';
import useRefreshOnVisible from '../../hooks/useRefreshOnVisible';
import NotificationDetailModal from './NotificationDetailModal';
import { typeMeta } from './notificationTypes';

const CACHE_KEY = 'notifications';
const CACHE_TTL = 60000;

const timeAgo = (iso) => {
  const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
  if (mins < 1) return 'ahora';
  if (mins < 60) return `hace ${mins}m`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `hace ${hours}h`;
  return `hace ${Math.floor(hours / 24)}d`;
};

/**
 * Campana de notificaciones del header.
 *
 * La bandeja se lee en tres momentos, todos acotados y sin temporizadores:
 *   1. Al montar el header (servido desde cache si otra vista ya lo pidio).
 *   2. Al ABRIR el panel (lectura forzada: el usuario quiere el dato al dia).
 *   3. Al volver a la pestana, y solo si el dato cacheado ya vencio.
 *
 * Antes habia un `setInterval` de 60s que, junto con el del contador de ventas
 * del header, generaba trafico de fondo indefinido en TODAS las rutas: dos GET
 * intercalados por minuto y por pestana abierta, para siempre.
 *
 * La lista cataloga visualmente por tipo con chips filtrables; "Ver Detalles"
 * marca la notificacion como leida y abre la modal de plantilla dinamica.
 */
export default function NotificationBell() {
  const [unread, setUnread] = useState([]);
  const [recent, setRecent] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [typeFilter, setTypeFilter] = useState(null);
  const [detail, setDetail] = useState(null);
  const panelRef = useRef(null);

  /**
   * Identidad estable (deps vacias): no participa en ninguna cadena de
   * re-renders y puede usarse como dependencia de efectos sin reactivarlos.
   */
  const fetchNotifications = useCallback(async ({ force = false } = {}) => {
    try {
      const data = await cachedGet(
        CACHE_KEY,
        () => api.get('/notifications').then((res) => res.data.data),
        { ttl: CACHE_TTL, force }
      );
      setUnread(data.unread);
      setRecent(data.recent);
      setUnreadCount(data.unread_count);
    } catch {
      // Silencioso: la campana no debe romper el header si el endpoint falla.
    }
  }, []);

  // Carga unica al montar el header. Sin intervalos: nada vuelve a pedirse
  // salvo por una accion explicita del usuario.
  useEffect(() => {
    fetchNotifications();
  }, [fetchNotifications]);

  // Al regresar a la pestana refrescamos, pero solo si el dato ya vencio.
  useRefreshOnVisible(() => {
    if (isStale(CACHE_KEY, CACHE_TTL)) fetchNotifications({ force: true });
  });

  const allItems = useMemo(() => {
    const items = [
      ...unread.map(n => ({ ...n, _unread: true })),
      ...recent.map(n => ({ ...n, _unread: false })),
    ];
    return typeFilter ? items.filter(n => n.type === typeFilter) : items;
  }, [unread, recent, typeFilter]);

  /** Tipos presentes en la bandeja, para los chips de catalogacion. */
  const presentTypes = useMemo(
    () => [...new Set([...unread, ...recent].map(n => n.type))],
    [unread, recent]
  );

  const openDetail = async (notification) => {
    setDetail(notification);
    panelRef.current?.hide();

    if (!notification.read_at) {
      try {
        await api.post(`/notifications/${notification.id}/read`);
        fetchNotifications({ force: true });
      } catch {
        // El acuse fallido se reintenta en el siguiente poll.
      }
    }
  };

  return (
    <>
      <button
        onClick={(e) => panelRef.current?.toggle(e)}
        className="relative flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700"
        title="Notificaciones"
      >
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        {unreadCount > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">
            {unreadCount > 9 ? '9+' : unreadCount}
          </span>
        )}
      </button>

      <OverlayPanel
        ref={panelRef}
        // Lectura fresca SOLO al abrir (no al cerrar): una peticion por
        // apertura deliberada del usuario, nunca en segundo plano.
        onShow={() => fetchNotifications({ force: true })}
        pt={{
          root: { className: 'rounded-2xl border border-slate-200 shadow-2xl mt-2 w-96 max-w-[calc(100vw-2rem)]' },
          content: { className: 'p-0' },
        }}
      >
        <div className="border-b border-slate-100 px-4 py-3">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-900">Notificaciones</h3>
            {unreadCount > 0 && (
              <span className="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-600">
                {unreadCount} sin leer
              </span>
            )}
          </div>

          {presentTypes.length > 1 && (
            <div className="mt-2 flex flex-wrap gap-1.5">
              <button
                onClick={() => setTypeFilter(null)}
                className={`cursor-pointer rounded-full px-2 py-0.5 text-[10px] font-semibold transition-colors ${!typeFilter ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
              >
                Todas
              </button>
              {presentTypes.map((type) => (
                <button
                  key={type}
                  onClick={() => setTypeFilter(typeFilter === type ? null : type)}
                  className={`cursor-pointer rounded-full px-2 py-0.5 text-[10px] font-semibold transition-colors ${typeFilter === type ? 'bg-slate-800 text-white' : typeMeta(type).chip + ' hover:opacity-80'}`}
                >
                  {typeMeta(type).label}
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="max-h-96 overflow-y-auto">
          {allItems.length === 0 ? (
            <div className="px-4 py-10 text-center">
              <i className="pi pi-bell-slash mb-2 text-2xl text-slate-300" />
              <p className="text-sm text-slate-400">Sin notificaciones por ahora.</p>
            </div>
          ) : (
            <ul className="divide-y divide-slate-50">
              {allItems.map((n) => {
                const meta = typeMeta(n.type);
                return (
                  <li key={n.id} className={n._unread ? 'bg-indigo-50/40' : ''}>
                    <div className="flex items-start gap-3 px-4 py-3">
                      <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${meta.iconBox}`}>
                        <i className={`pi ${meta.icon} text-sm`} />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <p className="truncate text-sm font-medium text-slate-900">{meta.label}</p>
                          {n._unread && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-500" />}
                        </div>
                        <p className="mt-0.5 line-clamp-2 text-xs text-slate-500">{meta.summary(n.data)}</p>
                        <div className="mt-1.5 flex items-center justify-between">
                          <span className="text-[11px] text-slate-400">{timeAgo(n.created_at)}</span>
                          <button
                            onClick={() => openDetail(n)}
                            className="cursor-pointer text-[11px] font-semibold text-indigo-600 hover:text-indigo-800"
                          >
                            Ver Detalles →
                          </button>
                        </div>
                      </div>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </OverlayPanel>

      <NotificationDetailModal notification={detail} onHide={() => setDetail(null)} />
    </>
  );
}
