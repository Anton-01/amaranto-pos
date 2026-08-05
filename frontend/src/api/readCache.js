/**
 * Cache de proceso con TTL, deduplicacion y suscripciones (stale-while-revalidate).
 *
 * Motivo original: los widgets del header (campana de notificaciones y contador
 * de "ventas hoy") viven dentro de <AppLayout/>, que cada pagina renderiza por
 * su cuenta. Eso significa que el header se MONTA DE NUEVO en cada navegacion
 * y, sin esta cache, cada cambio de vista relanzaba ambas peticiones.
 *
 * FASE 11 — la cache deja de ser un memo auxiliar y pasa a ser la fuente de
 * verdad de las vistas criticas (POS y Mesas). Para eso se le agregaron:
 *
 *  - `subscribe(key, fn)`: los componentes se enteran de los cambios sin
 *    sondear. Es la base de `useCachedResource` via useSyncExternalStore.
 *  - `peek(key)`: instantanea sincrona, sin disparar red. Permite que el
 *    primer render ya pinte datos y no un spinner.
 *  - `prefetch(key, fetcher)`: calienta una clave que nadie consume todavia.
 *    Es lo que dispara el hover sobre un enlace del menu.
 *  - `mutate(key, data)`: siembra un dato ya conocido (p. ej. la respuesta de
 *    un POST) para que la siguiente lectura no vuelva a la red.
 *
 * Garantias:
 *  - Un remonte dentro del TTL reutiliza el dato ya cargado: cero red.
 *  - Dos consumidores que piden la misma clave a la vez comparten la peticion
 *    en vuelo (deduplicacion), nunca se duplica el trafico.
 *  - `force: true` ignora la cache: reservado para acciones explicitas del
 *    usuario (abrir la campana, acusar una notificacion como leida).
 *
 * Deliberadamente NO hay temporizadores aqui: esta capa solo evita trabajo,
 * jamas lo genera por su cuenta.
 *
 * INVARIANTE: las entradas son INMUTABLES. Toda modificacion reemplaza el
 * objeto entero en el Map. `useSyncExternalStore` compara la instantanea por
 * identidad y entraria en un bucle infinito de render si mutaramos en sitio.
 */
const entries = new Map();
const listeners = new Map();

function notify(key) {
  const subscribers = listeners.get(key);
  if (!subscribers) return;
  // Copia defensiva: un listener puede desuscribirse durante la iteracion.
  for (const listener of [...subscribers]) listener();
}

function write(key, entry) {
  entries.set(key, entry);
  notify(key);
}

/**
 * Suscribe un listener a los cambios de una clave.
 *
 * @returns {() => void} funcion de baja
 */
export function subscribe(key, listener) {
  if (!listeners.has(key)) listeners.set(key, new Set());
  listeners.get(key).add(listener);

  return () => {
    const subscribers = listeners.get(key);
    if (!subscribers) return;
    subscribers.delete(listener);
    if (subscribers.size === 0) listeners.delete(key);
  };
}

/**
 * Instantanea cruda de una clave, sin disparar peticiones.
 *
 * La identidad del objeto solo cambia cuando cambia el contenido, que es justo
 * lo que useSyncExternalStore necesita para no re-renderizar de mas.
 *
 * @returns {{ data?: any, at?: number, promise?: Promise<any>, error?: any }|undefined}
 */
export function peek(key) {
  return entries.get(key);
}

/**
 * @param {string} key       Identificador logico del recurso.
 * @param {() => Promise<any>} fetcher  Peticion real (se ejecuta solo si hace falta).
 * @param {{ ttl?: number, force?: boolean }} options
 */
export async function cachedGet(key, fetcher, { ttl = 60000, force = false } = {}) {
  const entry = entries.get(key);

  if (entry) {
    if (entry.promise) return entry.promise;
    if (!force && 'data' in entry && Date.now() - entry.at < ttl) return entry.data;
  }

  const promise = fetcher()
    .then((data) => {
      write(key, { at: Date.now(), data });
      return data;
    })
    .catch((error) => {
      const previous = entries.get(key);

      // Revalidacion fallida sobre un dato ya cargado: se CONSERVA el dato
      // viejo y se anota el error. En un POS, un catalogo de hace un minuto
      // es infinitamente mas util que una pantalla vacia por un blip de red.
      if (previous && 'data' in previous) {
        write(key, { at: previous.at, data: previous.data, error });
      } else {
        entries.delete(key);
        notify(key);
      }

      throw error;
    });

  write(key, { ...entry, promise });

  return promise;
}

/** true si no hay dato para `key` o si el que hay ya supero su TTL. */
export function isStale(key, ttl = 60000) {
  const entry = entries.get(key);
  if (!entry || entry.promise) return false;
  return Date.now() - entry.at >= ttl;
}

/**
 * Calienta una clave sin consumirla. Pensado para el prefetch por intencion
 * (hover sobre un enlace): si el dato ya esta fresco no hace nada, y si la
 * peticion falla se traga el error — el usuario ni siquiera ha navegado aun,
 * no hay nada que reportarle.
 */
export function prefetch(key, fetcher, { ttl = 60000 } = {}) {
  const entry = entries.get(key);
  if (entry?.promise) return;
  if (entry && 'data' in entry && Date.now() - entry.at < ttl) return;

  cachedGet(key, fetcher, { ttl }).catch(() => {});
}

/** Siembra un dato ya conocido (respuesta de un POST, actualizacion optimista). */
export function mutate(key, data) {
  write(key, { at: Date.now(), data });
}

/** Descarta el dato cacheado para forzar una lectura fresca en el proximo uso. */
export function invalidate(key) {
  entries.delete(key);
  notify(key);
}

/** Invalida todas las claves que empiecen por un prefijo (p. ej. "pos:"). */
export function invalidatePrefix(prefix) {
  for (const key of [...entries.keys()]) {
    if (key.startsWith(prefix)) invalidate(key);
  }
}
