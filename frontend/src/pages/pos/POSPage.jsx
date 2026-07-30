import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import CheckoutModal from '../../components/pos/CheckoutModal';
import PrintConfirmationModal from '../../components/pos/PrintConfirmationModal';
import useOnlineStatus, { getOfflineQueue, clearOfflineQueue } from '../../hooks/useOnlineStatus';
import useCronosAgent from '../../hooks/useCronosAgent';

export default function POSPage() {
  const navigate = useNavigate();
  const isOnline = useOnlineStatus();
  const cronosAgent = useCronosAgent();
  const syncingRef = useRef(false);

  const [productGroups, setProductGroups] = useState([]);
  const [promotions, setPromotions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');

  const [cart, setCart] = useState([]);
  const [showCheckout, setShowCheckout] = useState(false);
  const [pendingPrint, setPendingPrint] = useState(null);

  const [cashRegister, setCashRegister] = useState(undefined);
  const [openingBalance, setOpeningBalance] = useState(0);
  const [openingCash, setOpeningCash] = useState(false);
  const [taxRate, setTaxRate] = useState(0.16);

  const checkCashRegister = useCallback(async () => {
    try {
      const res = await api.get('/cash-registers/active');
      setCashRegister(res.data.data);
    } catch {
      setCashRegister(null);
    }
  }, []);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [groupRes, promoRes] = await Promise.all([
        api.get('/products/grouped'),
        api.get('/promotions/active'),
      ]);
      setProductGroups(groupRes.data.data);
      setPromotions(promoRes.data.data);
    } catch {
      toast.error('Error al cargar datos del POS.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { checkCashRegister(); }, [checkCashRegister]);
  useEffect(() => { if (cashRegister) fetchData(); }, [cashRegister, fetchData]);
  useEffect(() => {
    api.get('/tax-rate').then(res => setTaxRate(res.data.data.rate)).catch(() => {});
  }, []);

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
    fetchData();

    if (order && !order._offline) {
      setPendingPrint({
        order,
        printerData: meta.printerData || null,
        ticketConfig: meta.ticketConfig || null,
      });
    }
  };

  if (cashRegister === undefined) {
    return (
      <AppLayout>
        <div className="flex h-64 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      </AppLayout>
    );
  }

  if (!cashRegister) {
    return (
      <AppLayout>
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
      </AppLayout>
    );
  }

  if (loading) {
    return (
      <AppLayout>
        <div className="flex h-64 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      </AppLayout>
    );
  }

  const shiftFolio = cashRegister?.id ? cashRegister.id.substring(0, 8).toUpperCase() : '---';
  const cashierName = cashRegister?.user?.name || 'Operador';
  const formattedDate = currentTime.toLocaleDateString('es-MX', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  const formattedTime = currentTime.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const formattedOpeningBalance = parseFloat(cashRegister?.opening_balance || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });

  return (
    <AppLayout>
      {/* Shift Status Card */}
      <div className="mb-5 rounded-xl bg-white px-5 py-3.5 shadow-sm ring-1 ring-slate-200">
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
          <div className="text-right">
            <p className="text-lg font-bold tabular-nums text-indigo-600">{formattedTime}</p>
          </div>
          <div className="flex items-center gap-4 rounded-lg bg-slate-50 px-4 py-2">
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

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Product catalog */}
        <div className="lg:col-span-2">
          <div className="mb-4">
            <InputText
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Buscar producto por nombre o SKU..."
              className="w-full rounded-lg border-slate-200 px-4 py-3 text-sm shadow-sm"
              pt={{ root: { className: 'w-full' } }}
            />
          </div>

          <div className="space-y-4">
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
                        className="cursor-pointer group relative rounded-lg border border-slate-200 p-3 text-left transition-all hover:border-indigo-300 hover:shadow-md disabled:opacity-40 disabled:cursor-not-allowed"
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
        <div className="lg:col-span-1">
          <div className="sticky top-6 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div className="border-b border-slate-200 p-4">
              <h2 className="text-lg font-semibold text-slate-900">Ticket de Venta</h2>
              {hasActivePromotion && (
                <div className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 font-medium">
                  Promocion aplicada — Limite: 1 por ticket
                </div>
              )}
            </div>

            <div className="max-h-96 overflow-y-auto">
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
                            className="shrink-0 text-slate-400 hover:text-rose-500 transition-colors"
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
                              className="flex h-6 w-6 items-center justify-center rounded bg-slate-100 text-sm font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-40"
                            >
                              -
                            </button>
                            <span className="w-8 text-center text-sm font-medium">{item.quantity}</span>
                            <button
                              onClick={() => updateQuantity(index, item.quantity + 1)}
                              className="flex h-6 w-6 items-center justify-center rounded bg-slate-100 text-sm font-bold text-slate-600 hover:bg-slate-200"
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

            {/* Totals & Checkout */}
            {cart.length > 0 && (
              <div className="border-t border-slate-200 p-4">
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

    </AppLayout>
  );
}
