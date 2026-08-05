import { useCallback, useEffect, useRef, useSyncExternalStore } from 'react';
import { cachedGet, peek, subscribe } from '../api/readCache';

/**
 * Lectura stale-while-revalidate sobre la cache de proceso (Fase 11).
 *
 * El contrato que elimina el parpadeo: si la clave ya tiene dato, el PRIMER
 * render lo devuelve — sincronamente, antes de cualquier efecto — y la
 * revalidacion ocurre despues, en segundo plano, sin desmontar nada. El
 * componente nunca pasa por un estado "vacio" intermedio al remontarse.
 *
 * Se apoya en `useSyncExternalStore` y no en un useState+useEffect porque es
 * la unica forma de que React lea el valor externo durante el render (con
 * useState el primer render seria `undefined` y habria un frame en blanco).
 *
 * @param {string|null} key   Clave logica; `null` deshabilita la lectura.
 * @param {() => Promise<any>} fetcher
 * @param {{ staleTime?: number, enabled?: boolean }} options
 *        staleTime — ventana durante la cual el dato se considera fresco y NO
 *        se revalida al montar. Es el parametro que decide cuanta red se
 *        ahorra al alternar entre vistas.
 *
 * @returns {{ data: any, isLoading: boolean, isValidating: boolean, error: any, refresh: (opts?) => Promise<any> }}
 */
export default function useCachedResource(key, fetcher, { staleTime = 60000, enabled = true } = {}) {
  // El fetcher suele llegar como arrow inline: guardarlo en una ref evita que
  // su identidad cambiante reinicie el efecto de revalidacion en cada render.
  const fetcherRef = useRef(fetcher);
  useEffect(() => {
    fetcherRef.current = fetcher;
  });

  const entry = useSyncExternalStore(
    useCallback((onChange) => (key ? subscribe(key, onChange) : () => {}), [key]),
    useCallback(() => (key ? peek(key) : undefined), [key]),
    useCallback(() => (key ? peek(key) : undefined), [key])
  );

  const hasData = !!entry && 'data' in entry;

  const refresh = useCallback(
    ({ force = true } = {}) => {
      if (!key) return Promise.resolve(undefined);

      return cachedGet(key, () => fetcherRef.current(), { ttl: staleTime, force });
    },
    [key, staleTime]
  );

  useEffect(() => {
    if (!key || !enabled) return;

    const current = peek(key);

    // Ya hay una peticion en vuelo (otro consumidor, o un prefetch por hover):
    // engancharse a ella en lugar de lanzar una segunda.
    if (current?.promise) return;

    const fresh = current && 'data' in current && Date.now() - current.at < staleTime;
    if (fresh) return;

    // Revalidacion silenciosa: `cachedGet` conserva el dato previo si falla,
    // asi que un error aqui no vacia la pantalla. El componente lo expone via
    // `error` y decide si molestar al usuario.
    cachedGet(key, () => fetcherRef.current(), { ttl: staleTime }).catch(() => {});
  }, [key, enabled, staleTime]);

  return {
    data: hasData ? entry.data : undefined,
    // Solo es "carga" cuando no hay NADA que pintar. Con dato en cache esto es
    // false desde el primer render: de ahi la transicion instantanea.
    isLoading: enabled && !hasData && !entry?.error,
    isValidating: !!entry?.promise,
    error: entry?.error,
    refresh,
  };
}
