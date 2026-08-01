/**
 * Cache de proceso con TTL y deduplicacion para lecturas de solo-visualizacion.
 *
 * Motivo: los widgets del header (campana de notificaciones y contador de
 * "ventas hoy") viven dentro de <AppLayout/>, que cada pagina renderiza por su
 * cuenta. Eso significa que el header se MONTA DE NUEVO en cada navegacion y,
 * sin esta cache, cada cambio de vista relanzaba ambas peticiones.
 *
 * Con la cache:
 *  - Un remonte dentro del TTL reutiliza el dato ya cargado: cero red.
 *  - Dos consumidores que piden la misma clave a la vez comparten la peticion
 *    en vuelo (deduplicacion), nunca se duplica el trafico.
 *  - `force: true` ignora la cache: reservado para acciones explicitas del
 *    usuario (abrir la campana, acusar una notificacion como leida).
 *
 * Deliberadamente NO hay temporizadores aqui: esta capa solo evita trabajo,
 * jamas lo genera por su cuenta.
 */
const entries = new Map();

/**
 * @param {string} key       Identificador logico del recurso.
 * @param {() => Promise<any>} fetcher  Peticion real (se ejecuta solo si hace falta).
 * @param {{ ttl?: number, force?: boolean }} options
 */
export async function cachedGet(key, fetcher, { ttl = 60000, force = false } = {}) {
  const entry = entries.get(key);

  if (entry) {
    if (entry.promise) return entry.promise;
    if (!force && Date.now() - entry.at < ttl) return entry.data;
  }

  const promise = fetcher()
    .then((data) => {
      entries.set(key, { at: Date.now(), data });
      return data;
    })
    .catch((error) => {
      entries.delete(key);
      throw error;
    });

  entries.set(key, { ...entry, promise });
  return promise;
}

/** true si no hay dato para `key` o si el que hay ya supero su TTL. */
export function isStale(key, ttl = 60000) {
  const entry = entries.get(key);
  if (!entry || entry.promise) return false;
  return Date.now() - entry.at >= ttl;
}

/** Descarta el dato cacheado para forzar una lectura fresca en el proximo uso. */
export function invalidate(key) {
  entries.delete(key);
}
