import { useEffect, useRef } from 'react';

/**
 * Ejecuta `onVisible` cuando la pestana vuelve al primer plano.
 *
 * Sustituye al polling por temporizador: mientras el usuario no esta mirando
 * la aplicacion no se consume red, y al volver se refresca una sola vez. El
 * callback se guarda en un ref para que el listener se registre UNA vez y no
 * dependa de la identidad de la funcion (que cambia en cada render).
 */
export default function useRefreshOnVisible(onVisible) {
  const callbackRef = useRef(onVisible);

  useEffect(() => {
    callbackRef.current = onVisible;
  }, [onVisible]);

  useEffect(() => {
    const handleVisibility = () => {
      if (document.visibilityState === 'visible') callbackRef.current();
    };

    document.addEventListener('visibilitychange', handleVisibility);
    return () => document.removeEventListener('visibilitychange', handleVisibility);
  }, []);
}
