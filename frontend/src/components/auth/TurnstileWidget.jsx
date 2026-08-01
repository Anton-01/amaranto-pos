import { useCallback, useEffect, useRef } from 'react';
import {
  TURNSTILE_ENABLED,
  TURNSTILE_SITE_KEY,
  loadTurnstileScript,
} from '../../lib/turnstile';

/**
 * Cloudflare Turnstile — escudo anti-bots invisible del login (Fase 9).
 *
 * El cajero NO resuelve puzzles: el widget se monta en modo `interaction-only`,
 * resuelve el reto en segundo plano y solo se hace visible en el caso raro de
 * que Cloudflare exija interaccion humana explicita.
 *
 * Si `VITE_TURNSTILE_SITE_KEY` no esta definida el escudo queda inactivo y el
 * componente no renderiza nada — asi el entorno local funciona sin llaves.
 */

/**
 * @param {(token: string|null) => void} onToken  Recibe el token de un solo uso
 *        (o null cuando expira / se resetea).
 * @param {(message: string) => void} [onError]   Fallo de carga o del reto.
 * @param {number} [resetSignal]  Cambiar este valor fuerza un nuevo token.
 *        Obligatorio tras un login fallido: los tokens son de un solo uso.
 */
export default function TurnstileWidget({ onToken, onError, resetSignal = 0 }) {
  const containerRef = useRef(null);
  const widgetIdRef = useRef(null);

  // Refs para los callbacks: evitan re-montar el widget en cada render del
  // formulario (cada tecla del password re-renderiza LoginPage).
  const onTokenRef = useRef(onToken);
  const onErrorRef = useRef(onError);

  useEffect(() => {
    onTokenRef.current = onToken;
    onErrorRef.current = onError;
  }, [onToken, onError]);

  const emitError = useCallback((message) => {
    onTokenRef.current?.(null);
    onErrorRef.current?.(message);
  }, []);

  useEffect(() => {
    if (!TURNSTILE_ENABLED) {
      return undefined;
    }

    let cancelled = false;

    loadTurnstileScript()
      .then((turnstile) => {
        if (cancelled || !containerRef.current || widgetIdRef.current !== null) {
          return;
        }

        widgetIdRef.current = turnstile.render(containerRef.current, {
          sitekey: TURNSTILE_SITE_KEY,
          appearance: 'interaction-only',
          size: 'flexible',
          theme: 'light',
          retry: 'auto',
          'retry-interval': 3000,
          callback: (token) => onTokenRef.current?.(token),
          'expired-callback': () => {
            onTokenRef.current?.(null);
            if (widgetIdRef.current !== null) {
              window.turnstile?.reset(widgetIdRef.current);
            }
          },
          'timeout-callback': () => emitError('La verificacion de seguridad expiro.'),
          'error-callback': () => {
            emitError('No se pudo completar la verificacion de seguridad.');
            // `true` evita que Turnstile siga reintentando en bucle.
            return true;
          },
        });
      })
      .catch((error) => {
        if (!cancelled) {
          emitError(error.message);
        }
      });

    return () => {
      cancelled = true;
      if (widgetIdRef.current !== null) {
        window.turnstile?.remove(widgetIdRef.current);
        widgetIdRef.current = null;
      }
    };
  }, [emitError]);

  // Los tokens son de un solo uso: tras cada intento de login hay que pedir
  // uno nuevo o el backend rechazaria el reenvio como token ya consumido.
  useEffect(() => {
    if (!TURNSTILE_ENABLED || resetSignal === 0 || widgetIdRef.current === null) {
      return;
    }

    onTokenRef.current?.(null);
    window.turnstile?.reset(widgetIdRef.current);
  }, [resetSignal]);

  if (!TURNSTILE_ENABLED) {
    return null;
  }

  return <div ref={containerRef} className="flex justify-center empty:hidden" />;
}
