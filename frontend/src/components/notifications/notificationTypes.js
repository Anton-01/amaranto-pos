/**
 * Registro de tipos de notificacion.
 *
 * El backend etiqueta cada notificacion con `type` y congela su payload en
 * `data`. Este registro traduce esa etiqueta a presentacion: icono, color,
 * titulo/resumen para la lista de la campana, y la plantilla de detalle que la
 * modal inyecta dinamicamente. Un tipo desconocido cae en DEFAULT_TYPE y se
 * renderiza de forma generica en lugar de romper la campana.
 */
import { AutoCashClosingDetail, GenericDetail } from './NotificationDetailTemplates';

const fmt = (v) => `$${Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

export const DEFAULT_TYPE = {
  label: 'Notificación del Sistema',
  icon: 'pi-info-circle',
  chip: 'bg-slate-100 text-slate-600',
  iconBox: 'bg-slate-50 text-slate-500',
  summary: () => 'Evento del sistema registrado.',
  Detail: GenericDetail,
};

export const NOTIFICATION_TYPES = {
  auto_cash_closing: {
    label: 'Cierre Automático de Caja',
    icon: 'pi-lock',
    chip: 'bg-indigo-100 text-indigo-700',
    iconBox: 'bg-indigo-50 text-indigo-600',
    summary: (data) =>
      `${data?.registers_closed ?? 0} caja${(data?.registers_closed ?? 0) !== 1 ? 's' : ''} cerrada${(data?.registers_closed ?? 0) !== 1 ? 's' : ''} automáticamente · esperado ${fmt(data?.total_expected)}`,
    Detail: AutoCashClosingDetail,
  },
};

export const typeMeta = (type) => NOTIFICATION_TYPES[type] ?? DEFAULT_TYPE;
