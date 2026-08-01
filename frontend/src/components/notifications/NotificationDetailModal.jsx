import { Dialog } from 'primereact/dialog';
import { typeMeta } from './notificationTypes';

/**
 * Modal de detalles reutilizable.
 *
 * Evalua la etiqueta `type` de la notificacion contra el registro de tipos e
 * inyecta dinamicamente la plantilla visual correspondiente. Agregar un nuevo
 * tipo de notificacion no toca esta modal: solo se registra en
 * notificationTypes.jsx.
 */
export default function NotificationDetailModal({ notification, onHide }) {
  const meta = typeMeta(notification?.type);
  const Detail = meta.Detail;

  return (
    <Dialog
      visible={notification !== null}
      onHide={onHide}
      modal
      header={null}
      className="w-full max-w-2xl"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30' },
        root: { className: 'rounded-2xl border-0 shadow-2xl' },
        content: { className: 'p-0' },
      }}
    >
      {notification && (
        <div className="p-6">
          <div className="mb-5 flex items-center gap-3">
            <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${meta.iconBox}`}>
              <i className={`pi ${meta.icon} text-lg`} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <h3 className="text-lg font-semibold text-slate-900">{meta.label}</h3>
                <span className={`rounded px-1.5 py-0.5 font-mono text-[10px] font-semibold ${meta.chip}`}>
                  {notification.type}
                </span>
              </div>
              <p className="text-xs text-slate-500">
                {new Date(notification.created_at).toLocaleString('es-MX', {
                  weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
                  hour: '2-digit', minute: '2-digit',
                })}
              </p>
            </div>
          </div>

          <Detail data={notification.data} />
        </div>
      )}
    </Dialog>
  );
}
