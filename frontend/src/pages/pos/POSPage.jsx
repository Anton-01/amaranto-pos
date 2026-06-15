import { useState, useEffect, useCallback, useMemo } from 'react';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';

const paymentMethods = [
  { label: 'Efectivo', value: 'efectivo' },
  { label: 'Tarjeta', value: 'tarjeta' },
  { label: 'Transferencia', value: 'transferencia' },
];

export default function POSPage() {
  const [productGroups, setProductGroups] = useState([]);
  const [promotions, setPromotions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');

  const [cart, setCart] = useState([]);
  const [paymentMethod, setPaymentMethod] = useState('efectivo');
  const [customLegend, setCustomLegend] = useState('');
  const [submitting, setSubmitting] = useState(false);

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

  useEffect(() => { fetchData(); }, [fetchData]);

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

  const subtotal = useMemo(() =>
    cart.reduce((sum, i) => sum + (i.sale_price * i.quantity) - i.discount, 0),
  [cart]);

  const ivaRate = 0.16;
  const ivaTotal = subtotal * ivaRate;
  const total = subtotal + ivaTotal;

  const handleSubmitOrder = async () => {
    if (cart.length === 0) {
      toast.error('El carrito esta vacio.');
      return;
    }

    setSubmitting(true);
    toast.info('Validando orden...', { description: 'El backend verificara la regla de 1 promocion por ticket.' });

    try {
      const payload = {
        cash_register_id: '00000000-0000-0000-0000-000000000000',
        payment_method: paymentMethod,
        custom_legend: customLegend || null,
        items: cart.map(i => ({
          product_id: i.product_id,
          quantity: i.quantity,
          promotion_id: i.promotion_id,
        })),
      };

      await api.post('/orders', payload);
      toast.success('Orden registrada exitosamente.');
      setCart([]);
      setCustomLegend('');
    } catch (err) {
      const data = err.response?.data;
      if (data?.code === 'ERR_POS_PROMOTION_LIMIT_EXCEEDED') {
        toast.error('Limite de promociones excedido', {
          description: data.message,
        });
      } else {
        toast.error(data?.message || 'Error al procesar la orden.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <AppLayout>
        <div className="flex h-64 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
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
                  {group.items.map((product) => (
                    <button
                      key={product.id}
                      onClick={() => addToCart(product)}
                      disabled={product.current_stock <= 0}
                      className="group relative rounded-lg border border-slate-200 p-3 text-left transition-all hover:border-indigo-300 hover:shadow-md disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                      <p className="text-sm font-medium text-slate-900 truncate">{product.name}</p>
                      <p className="text-xs text-slate-500 font-mono">{product.sku}</p>
                      <div className="mt-2 flex items-center justify-between">
                        <span className="text-sm font-bold text-indigo-600">
                          ${parseFloat(product.sale_price).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                        </span>
                        <span className={`text-xs ${product.current_stock <= product.minimum_stock ? 'text-rose-500 font-semibold' : 'text-slate-400'}`}>
                          {product.current_stock} uds
                        </span>
                      </div>
                    </button>
                  ))}
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
                    <span>IVA (16%)</span>
                    <span>${ivaTotal.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                  </div>
                  <div className="flex justify-between text-lg font-bold text-slate-900 pt-2 border-t border-slate-100">
                    <span>Total</span>
                    <span>${total.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                  </div>
                </div>

                <div className="mb-3">
                  <Dropdown
                    value={paymentMethod}
                    options={paymentMethods}
                    onChange={(e) => setPaymentMethod(e.value)}
                    className="w-full text-sm"
                    pt={{ root: { className: 'w-full' } }}
                  />
                </div>

                <div className="mb-4">
                  <InputText
                    value={customLegend}
                    onChange={(e) => setCustomLegend(e.target.value)}
                    placeholder="Leyenda adicional (opcional)"
                    className="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                    pt={{ root: { className: 'w-full' } }}
                  />
                </div>

                <Button
                  label={submitting ? 'Procesando...' : 'Cobrar'}
                  onClick={handleSubmitOrder}
                  disabled={submitting}
                  loading={submitting}
                  className="w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-emerald-500 disabled:opacity-50"
                  pt={{ root: { className: 'border-0' } }}
                />
              </div>
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
