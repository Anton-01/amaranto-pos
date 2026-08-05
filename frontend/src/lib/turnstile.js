/**
 * Carga y configuracion de Cloudflare Turnstile (Fase 9).
 *
 * Vive fuera del componente para que el script de Cloudflare se inserte UNA
 * sola vez por documento aunque el widget se monte y desmonte (React
 * StrictMode duplica los efectos en desarrollo).
 */

export const TURNSTILE_SITE_KEY = import.meta.env.VITE_TURNSTILE_SITE_KEY || '';

/** El escudo anti-bots solo se activa si hay site key inyectada en el build. */
export const TURNSTILE_ENABLED = Boolean(TURNSTILE_SITE_KEY);

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let scriptPromise = null;

export function loadTurnstileScript() {
  if (typeof window === 'undefined') {
    return Promise.reject(new Error('Turnstile requiere un navegador.'));
  }

  if (window.turnstile) {
    return Promise.resolve(window.turnstile);
  }

  if (scriptPromise) {
    return scriptPromise;
  }

  scriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    script.onload = () => {
      if (window.turnstile) {
        resolve(window.turnstile);
      } else {
        scriptPromise = null;
        reject(new Error('Turnstile cargo sin exponer su API.'));
      }
    };
    script.onerror = () => {
      scriptPromise = null;
      reject(new Error('No se pudo cargar el script de Turnstile.'));
    };
    document.head.appendChild(script);
  });

  return scriptPromise;
}
