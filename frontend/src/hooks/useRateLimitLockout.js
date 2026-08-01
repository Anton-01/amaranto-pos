import { useCallback, useEffect, useRef, useState } from 'react';
import { formatRetryAfter } from '../api/axios';

/**
 * Temporizador regresivo para bloqueos HTTP 429 (Fase 9).
 *
 * Trabaja sobre una MARCA DE TIEMPO ABSOLUTA, no sobre un contador que se
 * decrementa: los navegadores estrangulan `setInterval` en pestanas en
 * segundo plano, y un contador ingenuo se desincronizaria del `Retry-After`
 * real del servidor mientras el cajero mira otra ventana.
 */
export default function useRateLimitLockout() {
  const [remaining, setRemaining] = useState(0);
  const deadlineRef = useRef(0);
  const timerRef = useRef(null);

  const stopTimer = useCallback(() => {
    if (timerRef.current) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  const clear = useCallback(() => {
    stopTimer();
    deadlineRef.current = 0;
    setRemaining(0);
  }, [stopTimer]);

  const start = useCallback(
    (seconds) => {
      const total = Math.max(0, Math.ceil(Number(seconds) || 0));

      if (total === 0) {
        clear();
        return;
      }

      deadlineRef.current = Date.now() + total * 1000;
      setRemaining(total);

      stopTimer();
      timerRef.current = setInterval(() => {
        const left = Math.max(0, Math.ceil((deadlineRef.current - Date.now()) / 1000));
        setRemaining(left);

        if (left === 0) {
          stopTimer();
        }
      }, 1000);
    },
    [clear, stopTimer]
  );

  useEffect(() => stopTimer, [stopTimer]);

  const minutes = Math.floor(remaining / 60);
  const seconds = remaining % 60;

  return {
    locked: remaining > 0,
    remaining,
    // "14:52" — cuenta regresiva precisa para la alerta.
    countdown: `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`,
    // "15 minutos" — redaccion humana para el cuerpo del mensaje.
    humanized: formatRetryAfter(remaining),
    start,
    clear,
  };
}
