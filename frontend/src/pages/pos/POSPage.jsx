import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import { mutate, invalidate } from '../../api/readCache';
import { RESOURCE, fetcherOf, staleTimeOf, prefetchRoute, invalidateAfterSale } from '../../api/resources';
import useCachedResource from '../../hooks/useCachedResource';
import CheckoutModal from '../../components/pos/CheckoutModal';
import PrintConfirmationModal from '../../components/pos/PrintConfirmationModal';
import useOnlineStatus, { getOfflineQueue, clearOfflineQueue } from '../../hooks/useOnlineStatus';
import useCronosAgent from '../../hooks/useCronosAgent';
import { readCart, writeCart, clearCart } from './cartStore';

/** Referencia estable para las listas aun sin cargar (ver nota mas abajo). */
const EMPTY = Object.freeze([]);
const DEFAULT_TAX_RATE = 0.16;

export default function POSPage() {
  const navigate = useNavigate();
  const isOnline = useOnlineStatus();
  const cronosAgent = useCronosAgent();
  const syncingRef = useRef(false);

  const [search, setSearch] = useState('');

  /*
   * El carrito sobrevive a la navegacion (Fase 11). Antes vivia solo en este
   * useState: ir a Mesas a consultar algo y volver borraba la venta en curso.
   * Ahora se rehidrata desde un store de modulo y se sincroniza en cada cambio.
   */
  const [cart, setCart] = useState(readCart);
  useEffect(() => { writeCart(cart); }, [cart]);

  const [showCheckout, setShowCheckout] = useState(false);
  const [pendingPrint, setPendingPrint] = useState(null);

  const [openingBalance, setOpeningBalance] = useState(0);
  const [openingCash, setOpeningCash] = useState(false);

  /*
   * Las cuatro lecturas del POS salen de la cache compartida y se lanzan EN
   * PARALELO. Antes habia una cascada serial: `/cash-registers/active` tenia
   * que resolverse para que el efecto siguiente disparara `/products/grouped`
   * y `/promotions/active` — dos viajes de ida y vuelta encadenados antes de
   * poder pintar nada. Ahora los cuatro recursos parten a la vez y, si la
   * cache esta caliente, ninguno toca la red.
   */
  const cashRegisterQuery = useCachedResource(
    RESOURCE.POS_CASH_REGISTER,
    fetcherOf(RESOURCE.POS_CASH_REGISTER),
    { staleTime: staleTimeOf(RESOURCE.POS_CASH_REGISTER) }
  );
  const catalogQuery = useCachedResource(RESOURCE.POS_CATALOG, fetcherOf(RESOURCE.POS_CATALOG), {
    staleTime: staleTimeOf(RESOURCE.POS_CATALOG),
  });
  const promotionsQuery = useCachedResource(RESOURCE.POS_PROMOTIONS, fetcherOf(RESOURCE.POS_PROMOTIONS), {
    staleTime: staleTimeOf(RESOURCE.POS_PROMOTIONS),
  });
  const taxRateQuery = useCachedResource(RESOURCE.TAX_RATE, fetcherOf(RESOURCE.TAX_RATE), {
    staleTime: staleTimeOf(RESOURCE.TAX_RATE),
  });

  /*
   * EMPTY es una constante de modulo, no un `?? []` en linea: un literal aqui
   * crearia un array nuevo en cada render y romperia la memoizacion de todos
   * los useMemo que dependen de `productGroups` — justo el trabajo que esta
   * fase pretende ahorrar.
   */
  const productGroups = catalogQuery.data ?? EMPTY;
  const promotions = promotionsQuery.data ?? EMPTY;
  const taxRate = taxRateQuery.data ?? DEFAULT_TAX_RATE;

  // `undefined` = aun no se sabe; `null` = confirmado sin turno abierto.
  const cashRegister = cashRegisterQuery.data;
  const setCashRegister = useCallback((value) => mutate(RESOURCE.POS_CASH_REGISTER, value), []);

  // Solo bloquea el render la PRIMERA carga en frio. Con cache caliente todas
  // estas banderas nacen en false y la vista se pinta en el primer frame.
  const loading = catalogQuery.isLoading || promotionsQuery.isLoading;

  const fetchData = useCallback(() => {
    catalogQuery.refresh();
    promotionsQuery.refresh();
  }, [catalogQuery, promotionsQuery]);

  // Un fallo de revalidacion no vacia la pantalla (la cache conserva el dato
  // previo), pero el cajero debe enterarse de que esta viendo datos de antes.
  useEffect(() => {
    if (catalogQuery.error || promotionsQuery.error) {
      toast.error('No se pudo actualizar el catalogo.', {
        description: 'Se muestran los ultimos datos disponibles.',
      });
    }
  }, [catalogQuery.error, promotionsQuery.error]);

  useEffect(() => {
    if (!isOnline || syncingRef.current) return;

    const queue = getOfflineQueue();
    if (queue.length === 0) return;

    syncingRef.current = true;

    (async () => {
      let synced = 0;
      let failed = 0;

      for (const entry of queue) {
        const { _offline_id, _queued_at, ...payload } = entry;
        try {
          await api.post('/orders', payload);
          synced++;
        } catch {
          failed++;
        }
      }

      clearOfflineQueue();
      syncingRef.current = false;

      if (synced > 0) {
        toast.success(`Sincronizacion completada: ${synced} orden${synced !== 1 ? 'es' : ''} offline procesada${synced !== 1 ? 's' : ''}.`);
        fetchData();
      }
      if (failed > 0) {
        toast.error(`${failed} orden${failed !== 1 ? 'es' : ''} no pudieron sincronizarse.`);
      }
    })();
  }, [isOnline, fetchData]);

  const [currentTime, setCurrentTime] = useState(new Date());

  useEffect(() => {
    const timer = setInterval(() => setCurrentTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  const handleOpenCash = async () => {
    setOpeningCash(true);
    try {
      const res = await api.post('/cash-registers/open', { opening_balance: openingBalance ?? 0 });
      setCashRegister(res.data.data);
      // A new shift starts its sales counter at zero. Without this the header
      // pill would keep showing the previous shift's figure until its TTL ran
      // out — the exact confusion the shift-scoped count exists to remove.
      invalidate('header-shift-sales');
      toast.success('Caja abierta exitosamente. ¡Buen turno!');
    } catch (err) {
      const data = err.response?.data;
      if (data?.code === 'ERR_POS_CASH_REGISTER_ALREADY_OPEN') {
        setCashRegister(data.data);
        toast.info('Ya tienes una caja abierta.');
      } else {
        toast.error(data?.message || 'Error al abrir la caja.');
      }
    } finally {
      setOpeningCash(false);
    }
  };

  const activePromotionId = useMemo(() => {
    const ids = cart.map(item => item.promotion_id).filter(Boolean);
    return ids.length > 0 ? ids[0] : null;
  }, [cart]);

  const hasActivePromotion = activePromotionId !== null;

  const allProducts = useMemo(() => {
    return productGroups.flatMap(g => g.items);
  }, [productGroups]);

  const filteredGroups = useMemo(() => {
    if (!search.trim()) return productGroups;
    const s = search.toLowerCase();
    return productGroups
      .map(g => ({
        ...g,
        items: g.items.filter(p =>
          p.name.toLowerCase().includes(s) ||
          p.sku.toLowerCase().includes(s) ||
          (p.parent_sku && p.parent_sku.toLowerCase().includes(s))
        ),
      }))
      .filter(g => g.items.length > 0);
  }, [productGroups, search]);

  const addToCart = (product) => {
    setCart(prev => {
      const existing = prev.find(i => i.product_id === product.id && !i.promotion_id);
      if (existing) {
        return prev.map(i =>
          i.product_id === product.id && !i.promotion_id
            ? { ...i, quantity: i.quantity + 1 }
            : i
        );
      }
      return [...prev, {
        product_id: product.id,
        product_name: product.name,
        product_sku: product.sku,
        sale_price: parseFloat(product.sale_price),
        quantity: 1,
        promotion_id: null,
        promotion_name: null,
        discount: 0,
      }];
    });
  };

  const removeFromCart = (index) => {
    setCart(prev => prev.filter((_, i) => i !== index));
  };

  const updateQuantity = (index, qty) => {
    if (qty < 1) return;
    setCart(prev => prev.map((item, i) => i === index ? { ...item, quantity: qty } : item));
  };

  const getEligiblePromotions = (productId) => {
    return promotions.filter(p => {
      if (p.products.length === 0) return true;
      return p.products.some(pp => pp.id === productId);
    });
  };

  const applyPromotion = (cartIndex, promotionId) => {
    if (hasActivePromotion && activePromotionId !== promotionId) {
      toast.warning('Limite alcanzado', {
        description: 'Solo se permite 1 promocion por ticket. Retira la actual antes de aplicar otra.',
      });
      return;
    }

    const promo = promotions.find(p => p.id === promotionId);
    if (!promo) return;

    setCart(prev => prev.map((item, i) => {
      if (i !== cartIndex) return item;

      let discount = 0;
      if (promo.type === 'percentage') {
        discount = (item.sale_price * item.quantity) * (parseFloat(promo.value) / 100);
      } else if (promo.type === 'fixed_amount') {
        discount = Math.min(parseFloat(promo.value), item.sale_price * item.quantity);
      } else if (promo.type === 'freebie_100') {
        discount = item.sale_price * item.quantity;
      }

      return {
        ...item,
        promotion_id: promo.id,
        promotion_name: promo.name,
        discount,
      };
    }));

    toast.success(`Promocion "${promo.name}" aplicada.`);
  };

  const removePromotion = (cartIndex) => {
    setCart(prev => prev.map((item, i) =>
      i === cartIndex ? { ...item, promotion_id: null, promotion_name: null, discount: 0 } : item
    ));
  };

  const totalGross = useMemo(() =>
    cart.reduce((sum, i) => sum + (i.sale_price * i.quantity) - i.discount, 0),
  [cart]);

  const subtotal = totalGross / (1 + taxRate);
  const ivaTotal = totalGross - subtotal;
  const total = totalGross;

  // Post-venta: el carrito se limpia de inmediato (queda listo para la
  // siguiente venta) y la impresion queda en manos del usuario a traves del
  // PrintConfirmationModal. Nunca se dispara impresion automatica.
  const handleCheckoutSuccess = (order, meta = {}) => {
    setShowCheckout(false);
    setCart([]);
    clearCart();

    // La venta movio stock (y pudo liberar una mesa): se descarta el catalogo
    // cacheado para que la siguiente lectura —aqui o en Mesas— vaya a la red
    // aunque la ventana de frescura no haya vencido.
    invalidateAfterSale();
    fetchData();

    if (order && !order._offline) {
      setPendingPrint({
        order,
        printerData: meta.printerData || null,
        ticketConfig: meta.ticketConfig || null,
      });
    }
  };

  // Primera carga en frio: aun no se sabe si el cajero tiene turno abierto.
  // Al volver desde Mesas este estado no se atraviesa — el dato ya esta en cache.
  if (cashRegister === undefined) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
      </div>
    );
  }

  if (!cashRegister) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-md">
          <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-2xl ring-1 ring-slate-200">
            <div className="mb-6 flex flex-col items-center text-center">
              <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-indigo-50">
                <svg className="h-8 w-8 text-indigo-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
                </svg>
              </div>
              <h2 className="text-xl font-bold text-slate-900">Apertura de Turno de Caja</h2>
              <p className="mt-2 text-sm text-slate-500">
                Para comenzar a operar en el Punto de Venta, es necesario registrar el fondo inicial de caja.
              </p>
            </div>

            <div className="mb-6">
              <label className="mb-2 block text-sm font-medium text-slate-700">
                Monto Inicial de Caja (Fondo de Operación) *
              </label>
              <InputNumber
                value={openingBalance}
                onValueChange={(e) => setOpeningBalance(e.value)}
                mode="currency"
                currency="MXN"
                locale="es-MX"
                minFractionDigits={2}
                maxFractionDigits={2}
                min={0}
                disabled={openingCash}
                className="w-full"
                inputClassName="w-full rounded-lg border-slate-200 px-4 py-3 text-lg font-semibold text-center"
                pt={{ root: { className: 'w-full' } }}
              />
              <p className="mt-1.5 text-xs text-slate-400">
                Cuenta el efectivo físico en la caja e ingresa el monto exacto.
              </p>
            </div>

            <div className="flex gap-3">
              <Button
                label="Regresar al Panel"
                onClick={() => navigate('/dashboard')}
                disabled={openingCash}
                className="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 hover:bg-slate-50"
                pt={{ root: { className: 'border border-slate-200' } }}
              />
              <Button
                label={openingCash ? 'Abriendo Caja...' : 'Abrir Caja'}
                onClick={handleOpenCash}
                disabled={openingCash}
                loading={openingCash}
                className="flex-1 cursor-pointer rounded-lg bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                pt={{ root: { className: 'border-0' } }}
              />
            </div>

            <div className="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-xs text-amber-700">
              <strong>Nota:</strong> Este registro es obligatorio y quedará auditado con tu usuario, IP y hora del servidor.
            </div>
          </div>
        </div>
    );
  }

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
      </div>
    );
  }

  const shiftFolio = cashRegister?.id ? cashRegister.id.substring(0, 8).toUpperCase() : '---';
  const cashierName = cashRegister?.user?.name || 'Operador';
  const formattedDate = currentTime.toLocaleDateString('es-MX', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  const formattedTime = currentTime.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const formattedOpeningBalance = parseFloat(cashRegister?.opening_balance || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });

  return (
    <>
      {/*
        POS SHELL — ONE SCREEN, TWO INDEPENDENT SCROLLERS
        -------------------------------------------------
        From `lg` up the whole POS is pinned to the viewport and never scrolls
        as a page: the catalog scrolls inside its own column and the ticket
        stays put beside it. Before this, hunting for a product pushed the
        running total off screen, so the cashier scrolled back up to read the
        amount to charge on every single sale.

        The height subtracts the two chrome bands the page sits inside — the
        sticky 4rem header of AppLayout plus that layout's vertical padding
        (1.5rem top and bottom at `lg`) — so the column bottoms land exactly on
        the viewport edge and no page scrollbar is created.

        Below `lg` this is inert on purpose: `min-h-0` and the height only
        apply from `lg`, so a phone keeps ordinary page flow, where the ticket
        is a section under the catalog and the fixed summary bar at the bottom
        of this file is what keeps the total in sight.
      */}
      <div className="flex flex-col lg:h-[calc(100vh-7rem)] lg:min-h-0 lg:overflow-hidden">
        {/* Shift Status Card */}
        <div className="mb-4 shrink-0 rounded-xl bg-white px-3 py-3 shadow-sm ring-1 ring-slate-200 sm:mb-5 sm:px-5 sm:py-3.5">
          {/* Cashier + clock share the first row on a phone; the shift folio and
              opening balance drop to a full-width second row rather than being
              squeezed into a third of a 360px screen. */}
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-50">
                <i className="pi pi-user text-indigo-600" />
              </div>
              <div>
                <p className="text-sm font-semibold text-slate-900">{cashierName}</p>
                <p className="text-xs text-slate-500 capitalize">{formattedDate}</p>
              </div>
            </div>
            {/* Acceso al comedor: estrictamente a la izquierda del reloj */}
            <div className="ml-auto flex items-center gap-2 sm:gap-3">
              <button
                type="button"
                onClick={() => navigate('/mesas')}
                /*
                 * Prefetch por intencion: entre que el cajero apunta al boton y
                 * suelta el clic pasan 200-400 ms, de sobra para que el plano de
                 * mesas ya este en cache y la vista aparezca sin latencia.
                 * `onFocus` cubre el mismo camino por teclado.
                 */
                onMouseEnter={() => prefetchRoute('/mesas')}
                onFocus={() => prefetchRoute('/mesas')}
                title="Plano de Mesas"
                aria-label="Abrir plano de mesas"
                className="group flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition-all hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
              >
                <svg
                  className="h-5 w-5 text-slate-400 transition-colors group-hover:text-indigo-600"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={1.75}
                  stroke="currentColor"
                >
                  {/* Mesa redonda con cubiertos */}
                  <path strokeLinecap="round" strokeLinejoin="round" d="M3 9.75h18M4.5 9.75v-.75a7.5 7.5 0 0 1 15 0v.75M8.25 9.75V21m7.5-11.25V21M12 3v-.75" />
                </svg>
                <span className="hidden sm:inline">Mesas</span>
              </button>

              <div className="text-right">
                <p className="text-lg font-bold tabular-nums text-indigo-600">{formattedTime}</p>
              </div>
            </div>
            <div className="flex w-full items-center gap-4 rounded-lg bg-slate-50 px-3 py-2 sm:w-auto sm:px-4">
              <div>
                <p className="text-xs text-slate-500">Turno</p>
                <p className="text-xs font-mono font-semibold text-slate-700">{shiftFolio}</p>
              </div>
              <div className="h-6 w-px bg-slate-200" />
              <div>
                <p className="text-xs text-slate-500">Fondo inicial</p>
                <p className="text-sm font-semibold text-emerald-600">{formattedOpeningBalance}</p>
              </div>
            </div>
          </div>
        </div>

        {/* `min-h-0` is what makes the columns scroll instead of stretch: a grid
            item defaults to `min-height: auto`, which lets tall content push the
            track past the fixed height above and hand the overflow back to the
            page — the very thing this layout removes. */}
        <div className="grid grid-cols-1 gap-6 lg:min-h-0 lg:flex-1 lg:grid-cols-3">
          {/* Product catalog */}
          <div className="flex flex-col lg:col-span-2 lg:min-h-0">
            {/*
              The search box never leaves the screen. From `lg` it is a fixed
              row of the column, above the scroller, so it cannot move at all.
              Below `lg` the page itself scrolls, so it sticks — at `top-16`,
              not `top-0`, because AppLayout's own sticky header occupies that
              first 4rem and a `top-0` box would slide underneath it.
              The opaque background is required: without it the product cards
              would show through the box as they scroll past — and the gap to
              the first card is `pb-4` rather than `mb-4` on phones for the same
              reason, since a margin sits outside the painted area and would
              leave a 1rem window for a card edge to slide through.
            */}
            <div className="sticky top-16 z-10 shrink-0 bg-slate-50 pb-4 lg:top-0 lg:mb-4 lg:pb-0">
              <InputText
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Buscar producto por nombre o SKU..."
                className="w-full rounded-lg border-slate-200 bg-white px-4 py-3 text-sm shadow-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>

            {/* The catalog's own scroller. `overscroll-contain` stops a flick at
                the end of the list from carrying on into the page behind it. */}
            <div className="space-y-4 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain lg:pr-1">
              {filteredGroups.map((group, gi) => (
                <div key={gi} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                  {group.parent_sku && (
                    <h3 className="mb-3 text-sm font-semibold text-slate-500 uppercase tracking-wide">
                      {group.parent_sku}
                    </h3>
                  )}
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                    {group.items.map((product) => {
                      const isUnavailable = product.track_stock && product.current_stock <= 0;
                      return (
                        <button
                          key={product.id}
                          onClick={() => addToCart(product)}
                          disabled={isUnavailable}
                          className="group relative min-h-[88px] cursor-pointer rounded-lg border border-slate-200 p-3 text-left transition-all hover:border-indigo-300 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-40"
                        >
                          <p className="text-sm font-medium text-slate-900 truncate">{product.name}</p>
                          <p className="text-xs text-slate-500 font-mono">{product.sku}</p>
                          <div className="mt-2 flex items-center justify-between">
                            <span className="text-sm font-bold text-indigo-600">
                              ${parseFloat(product.sale_price).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                            </span>
                            {product.track_stock === false ? (
                              <span className="rounded bg-blue-50 px-1.5 py-0.5 text-xs font-medium text-blue-600">∞</span>
                            ) : (
                              <span className={`text-xs ${product.current_stock <= product.minimum_stock ? 'text-rose-500 font-semibold' : 'text-slate-400'}`}>
                                {product.current_stock} uds
                              </span>
                            )}
                          </div>
                        </button>
                      );
                    })}
                  </div>
                </div>
              ))}
              {filteredGroups.length === 0 && (
                <p className="py-12 text-center text-sm text-slate-400">No se encontraron productos.</p>
              )}
            </div>
          </div>

          {/* Cart */}
          <div className="lg:col-span-1 lg:flex lg:min-h-0 lg:flex-col">
            {/*
              `id` is the anchor for the mobile summary bar below, which scrolls
              the cashier here instead of making them hunt past the whole catalog.

              From `lg` the panel fills its column and stays put for the whole
              session — the total is always on screen, whatever the catalog is
              doing. It is a flex column so the ticket lines take the leftover
              height and scroll on their own, leaving the heading and the totals
              block anchored. On a phone the cart is a section of the page, not a
              side rail, and pinning it would cover the products.
            */}
            <div id="pos-cart-panel" className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200 lg:flex lg:min-h-0 lg:flex-1 lg:flex-col">
              <div className="shrink-0 border-b border-slate-200 p-4">
                <h2 className="text-lg font-semibold text-slate-900">Ticket de Venta</h2>
                {hasActivePromotion && (
                  <div className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 font-medium">
                    Promocion aplicada — Limite: 1 por ticket
                  </div>
                )}
              </div>

              <div className="max-h-96 overflow-y-auto overscroll-contain lg:max-h-none lg:min-h-0 lg:flex-1">
                {cart.length === 0 ? (
                  <p className="p-6 text-center text-sm text-slate-400">Agrega productos al ticket.</p>
                ) : (
                  <ul className="divide-y divide-slate-100">
                    {cart.map((item, index) => {
                      const itemTotal = (item.sale_price * item.quantity) - item.discount;
                      const eligible = getEligiblePromotions(item.product_id);
                      const canApplyPromo = !item.promotion_id && (!hasActivePromotion) && eligible.length > 0;
                      const isPromotionBlocked = !item.promotion_id && hasActivePromotion;

                      return (
                        <li key={index} className="p-3">
                          <div className="flex items-start justify-between gap-2">
                            <div className="flex-1 min-w-0">
                              <p className="text-sm font-medium text-slate-900 truncate">{item.product_name}</p>
                              <p className="text-xs text-slate-500 font-mono">{item.product_sku}</p>
                            </div>
                            <button
                              onClick={() => removeFromCart(index)}
                              className="-m-2 shrink-0 cursor-pointer p-2 text-slate-400 transition-colors hover:text-rose-500"
                            >
                              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>

                          <div className="mt-2 flex items-center justify-between">
                            <div className="flex items-center gap-1">
                              <button
                                onClick={() => updateQuantity(index, item.quantity - 1)}
                                disabled={item.quantity <= 1}
                                className="flex h-9 w-9 cursor-pointer items-center justify-center rounded bg-slate-100 text-base font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-40 lg:h-6 lg:w-6 lg:text-sm"
                              >
                                -
                              </button>
                              <span className="w-8 text-center text-sm font-medium">{item.quantity}</span>
                              <button
                                onClick={() => updateQuantity(index, item.quantity + 1)}
                                className="flex h-9 w-9 cursor-pointer items-center justify-center rounded bg-slate-100 text-base font-bold text-slate-600 hover:bg-slate-200 lg:h-6 lg:w-6 lg:text-sm"
                              >
                                +
                              </button>
                            </div>
                            <span className="text-sm font-semibold text-slate-900">
                              ${itemTotal.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                            </span>
                          </div>

                          {/* Promotion controls */}
                          {item.promotion_id ? (
                            <div className="mt-2 flex items-center justify-between rounded-lg bg-emerald-50 px-2 py-1.5">
                              <span className="text-xs font-medium text-emerald-700 truncate">
                                {item.promotion_name} (-${item.discount.toFixed(2)})
                              </span>
                              <button
                                onClick={() => removePromotion(index)}
                                className="ml-2 shrink-0 text-xs text-emerald-600 hover:text-emerald-800 font-medium"
                              >
                                Quitar
                              </button>
                            </div>
                          ) : canApplyPromo ? (
                            <div className="mt-2">
                              <Dropdown
                                options={eligible.map(p => ({ label: p.name, value: p.id }))}
                                onChange={(e) => applyPromotion(index, e.value)}
                                placeholder="Aplicar cupon..."
                                className="w-full text-xs"
                                pt={{
                                  root: { className: 'w-full border-dashed border-emerald-300 bg-emerald-50/50' },
                                  input: { className: 'text-xs py-1' },
                                }}
                              />
                            </div>
                          ) : isPromotionBlocked ? (
                            <p className="mt-2 text-xs text-amber-600 italic">
                              Cupon bloqueado (1 por ticket)
                            </p>
                          ) : null}
                        </li>
                      );
                    })}
                  </ul>
                )}
              </div>

              {/* Totals & Checkout — never scrolls away from the ticket panel. */}
              {cart.length > 0 && (
                <div className="shrink-0 border-t border-slate-200 p-4">
                  <div className="mb-4 space-y-1 text-sm">
                    <div className="flex justify-between text-slate-600">
                      <span>Subtotal</span>
                      <span>${subtotal.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                    </div>
                    <div className="flex justify-between text-slate-600">
                      <span>IVA ({(taxRate * 100).toFixed(0)}%)</span>
                      <span>${ivaTotal.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                    </div>
                    <div className="flex justify-between text-lg font-bold text-slate-900 pt-2 border-t border-slate-100">
                      <span>Total</span>
                      <span>${total.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                    </div>
                  </div>

                  <Button
                    label="Cobrar"
                    onClick={() => setShowCheckout(true)}
                    className="w-full cursor-pointer rounded-lg bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-emerald-500"
                    pt={{ root: { className: 'border-0' } }}
                  />
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/*
        MOBILE CHECKOUT BAR
        -------------------
        On a phone the cart sits below the whole catalog, so the running total —
        the number the cashier checks constantly — scrolls out of sight after
        the first few products. This bar keeps it pinned, and its button jumps
        straight to the ticket. It exists only below `lg`, where the cart is not
        already visible as a side rail, and only while there is something in it.

        The page gets matching bottom padding so the bar can never cover the
        last row of products.
      */}
      {cart.length > 0 && (
        <>
          <div className="h-20 lg:hidden" aria-hidden="true" />
          <div className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-3 py-2.5 shadow-[0_-4px_12px_rgba(15,23,42,0.06)] backdrop-blur-md lg:hidden">
            <div className="flex items-center justify-between gap-3">
              <div className="min-w-0">
                <p className="text-[11px] font-medium text-slate-500">
                  {cart.length} articulo{cart.length !== 1 ? 's' : ''} en el ticket
                </p>
                <p className="truncate text-lg font-bold tabular-nums text-slate-900">
                  ${total.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                </p>
              </div>
              <button
                type="button"
                onClick={() =>
                  document.getElementById('pos-cart-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
                }
                className="flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-lg bg-emerald-600 px-5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-emerald-500"
              >
                Ver ticket
                <i className="pi pi-arrow-down text-xs" />
              </button>
            </div>
          </div>
        </>
      )}

      <CheckoutModal
        visible={showCheckout}
        onHide={() => setShowCheckout(false)}
        cart={cart}
        taxRate={taxRate}
        onSuccess={handleCheckoutSuccess}
        isOnline={isOnline}
      />

      <PrintConfirmationModal
        visible={pendingPrint !== null}
        order={pendingPrint?.order}
        ticketConfig={pendingPrint?.ticketConfig}
        printerData={pendingPrint?.printerData}
        taxRate={taxRate}
        cronosAgent={cronosAgent}
        onClose={() => setPendingPrint(null)}
      />

    </>
  );
}
