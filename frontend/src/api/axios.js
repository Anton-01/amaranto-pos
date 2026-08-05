import axios from 'axios';
import { toast } from 'sonner';

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

/**
 * Evento global emitido en cada 429. Cualquier vista puede escucharlo para
 * reaccionar al bloqueo sin acoplarse al interceptor.
 */
export const RATE_LIMIT_EVENT = 'cronos:rate-limited';

const DEFAULT_RETRY_AFTER = 60;

/**
 * Lee los segundos de espera de una respuesta 429.
 *
 * `Retry-After` admite dos formatos segun el RFC: un entero de segundos o una
 * fecha HTTP. Se soportan ambos, y como ultimo recurso se cae al metadata del
 * catalogo de errores corporativo.
 */
export function parseRetryAfter(error) {
  const raw = error?.response?.headers?.['retry-after'];

  if (raw !== undefined && raw !== null && raw !== '') {
    const seconds = Number(raw);
    if (Number.isFinite(seconds) && seconds >= 0) {
      return Math.ceil(seconds);
    }

    const timestamp = Date.parse(raw);
    if (!Number.isNaN(timestamp)) {
      return Math.max(0, Math.ceil((timestamp - Date.now()) / 1000));
    }
  }

  const fromPayload = Number(error?.response?.data?.metadata?.retry_after_seconds);

  return Number.isFinite(fromPayload) && fromPayload > 0 ? fromPayload : DEFAULT_RETRY_AFTER;
}

/**
 * "930" -> "15 minutos". "45" -> "45 segundos".
 */
export function formatRetryAfter(seconds) {
  if (seconds < 60) {
    return `${seconds} segundo${seconds === 1 ? '' : 's'}`;
  }

  const minutes = Math.ceil(seconds / 60);

  return `${minutes} minuto${minutes === 1 ? '' : 's'}`;
}

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const data = error.response?.data;
    const status = error.response?.status;
    const url = error.config?.url || '';

    /*
     * Escudo de throttling (Fase 9). Se resuelve ANTES que el resto de
     * estados: un 429 nunca debe interpretarse como sesion invalida ni
     * arrastrar al cajero a /login perdiendo el contexto de trabajo.
     */
    if (status === 429) {
      const retryAfter = parseRetryAfter(error);

      error.retryAfter = retryAfter;

      window.dispatchEvent(
        new CustomEvent(RATE_LIMIT_EVENT, { detail: { retryAfter, url } })
      );

      // El login pinta su propia alerta con temporizador regresivo; un toast
      // encima solo duplicaria el mensaje.
      if (!url.includes('/auth/login')) {
        toast.error('Demasiadas peticiones', {
          description:
            data?.message ||
            `Espera ${formatRetryAfter(retryAfter)} antes de volver a intentar.`,
        });
      }

      return Promise.reject(error);
    }

    if (status === 403 && data?.code === 'ERR_AUTH_USER_SUSPENDED') {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('temp_token');
      toast.error('Cuenta suspendida', {
        description: data.message,
      });
      window.location.href = '/login';
      return Promise.reject(error);
    }

    if (status === 401 && data?.code !== 'ERR_AUTH_INVALID_CREDENTIALS' && data?.code !== 'ERR_AUTH_INVALID_2FA_CODE') {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
    }

    return Promise.reject(error);
  }
);

export default api;
