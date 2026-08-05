import api from './axios';
import { invalidate, prefetch } from './readCache';

/**
 * Registro central de los recursos de las rutas criticas (Fase 11).
 *
 * Tener claves, fetchers y ventanas de frescura en UN solo lugar es lo que
 * permite que la vista, el prefetch por hover y la invalidacion post-venta
 * hablen exactamente del mismo dato. Con las peticiones dispersas por los
 * componentes, un `staleTime` distinto en cada sitio reintroduce el problema
 * que esta fase vino a resolver.
 */

export const RESOURCE = {
  POS_CATALOG: 'pos:catalog',
  POS_PROMOTIONS: 'pos:promotions',
  POS_CASH_REGISTER: 'pos:cash-register',
  DINING_TABLES: 'dining:tables',
  TAX_RATE: 'config:tax-rate',
};

/**
 * Ventanas de frescura, calibradas por VOLATILIDAD REAL del dato, no por
 * comodidad:
 *
 *  - Catalogo y promociones cambian por una edicion deliberada en el panel de
 *    administracion: 60 s de tolerancia no arriesgan nada operativo.
 *  - El plano de mesas lo mueven varios meseros a la vez. 10 s mantiene la
 *    transicion instantanea y aun asi revalida en la practica en cada
 *    navegacion; ademas la vista ya refresca al recuperar el foco.
 *  - La caja activa gobierna el bloqueo del POS: ventana corta.
 *  - El IVA es configuracion global; cambiarlo es un evento anual.
 */
export const STALE_TIME = {
  [RESOURCE.POS_CATALOG]: 60_000,
  [RESOURCE.POS_PROMOTIONS]: 60_000,
  [RESOURCE.POS_CASH_REGISTER]: 20_000,
  [RESOURCE.DINING_TABLES]: 10_000,
  [RESOURCE.TAX_RATE]: 600_000,
};

export const fetchers = {
  [RESOURCE.POS_CATALOG]: () => api.get('/products/grouped').then((r) => r.data.data),

  [RESOURCE.POS_PROMOTIONS]: () => api.get('/promotions/active').then((r) => r.data.data),

  /*
   * El endpoint responde 200 con `data: null` cuando el cajero no tiene turno
   * abierto. Por eso NO se traga el error aqui: un fallo de red debe
   * propagarse como error y conservar el dato previo, no confundirse con
   * "no hay caja abierta" y mandar al cajero a la pantalla de apertura.
   */
  [RESOURCE.POS_CASH_REGISTER]: () => api.get('/cash-registers/active').then((r) => r.data.data ?? null),

  [RESOURCE.DINING_TABLES]: () =>
    api.get('/tables').then((r) => ({
      tables: r.data.data,
      summary: r.data.metadata.summary,
      zones: r.data.metadata.zones ?? [],
    })),

  [RESOURCE.TAX_RATE]: () => api.get('/tax-rate').then((r) => r.data.data.rate),
};

export const staleTimeOf = (key) => STALE_TIME[key] ?? 60_000;

/** Fetcher del recurso, listo para pasar a `useCachedResource`. */
export const fetcherOf = (key) => fetchers[key];

/**
 * Recursos que necesita cada ruta critica para pintarse por completo.
 * Es lo que se precarga al detectar intencion de navegar.
 */
const ROUTE_RESOURCES = {
  '/pos': [RESOURCE.POS_CASH_REGISTER, RESOURCE.POS_CATALOG, RESOURCE.POS_PROMOTIONS, RESOURCE.TAX_RATE],
  '/mesas': [RESOURCE.DINING_TABLES, RESOURCE.TAX_RATE],
};

/**
 * PREFETCH POR INTENCION.
 *
 * Se dispara con `onMouseEnter` / `onFocus` sobre los enlaces de navegacion:
 * entre que el usuario apunta y hace clic pasan 200-400 ms, tiempo de sobra
 * para que la peticion vuelva y el cambio de vista sea instantaneo.
 *
 * Es idempotente y silencioso por diseno: si el dato ya esta fresco no toca la
 * red, si hay una peticion en vuelo se engancha, y si falla no molesta a nadie
 * — el usuario ni siquiera ha navegado todavia.
 */
export function prefetchRoute(path) {
  const keys = ROUTE_RESOURCES[path];
  if (!keys) return;

  for (const key of keys) {
    prefetch(key, fetchers[key], { ttl: staleTimeOf(key) });
  }
}

/** ¿Vale la pena precargar esta ruta? Evita colgar handlers inutiles. */
export const isPrefetchable = (path) => Object.hasOwn(ROUTE_RESOURCES, path);

/**
 * Invalidacion tras una venta: el stock y el estado de las mesas acaban de
 * cambiar en el servidor, asi que la proxima lectura debe ir a la red aunque
 * la ventana de frescura no haya vencido.
 */
export function invalidateAfterSale() {
  invalidate(RESOURCE.POS_CATALOG);
  invalidate(RESOURCE.DINING_TABLES);
}
