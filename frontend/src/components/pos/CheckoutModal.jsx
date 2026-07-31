import { useState, useEffect, useRef, useMemo } from 'react';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputNumber } from 'primereact/inputnumber';
import { Button } from 'primereact/button';
import { Checkbox } from 'primereact/checkbox';
import { RadioButton } from 'primereact/radiobutton';
import { AutoComplete } from 'primereact/autocomplete';
import { toast } from 'sonner';
import api from '../../api/axios';
import TicketPreview from './TicketPreview';
import { addToOfflineQueue } from '../../hooks/useOnlineStatus';

/**
 * Sanitiza el monto recibido en tiempo real (en cada tecla).
 *
 * Descarta letras, simbolos y cualquier caracter no numerico antes de que
 * llegue al estado; acepta coma como separador decimal (teclados numericos
 * latinos) normalizandola a punto, permite un unico punto decimal y limita
 * a 2 decimales y 9 enteros.
 */
function sanitizeAmount(raw) {
  let clean = String(raw ?? '').replace(/,/g, '.').replace(/[^0-9.]/g, '');

  // Un solo separador decimal: se conserva el primero y se descartan los demas.
  const firstDot = clean.indexOf('.');
  if (firstDot !== -1) {
    clean = clean.slice(0, firstDot + 1) + clean.slice(firstDot + 1).replace(/\./g, '');
  }

  let [int = '', dec] = clean.split('.');

  // Ceros a la izquierda ("007" -> "7"), preservando el "0" de "0.50".
  int = int.replace(/^0+(?=\d)/, '').slice(0, 9);

  if (dec === undefined) return int;
  return `${int || '0'}.${dec.slice(0, 2)}`;
}

/** Convierte el texto sanitizado a numero; null si aun no hay monto valido. */
function parseAmount(value) {
  if (value === '' || value === '.') return null;
  const parsed = parseFloat(value);
  return Number.isFinite(parsed) ? parsed : null;
}

export default function CheckoutModal({ visible, onHide, cart, taxRate = 0.16, onSuccess, isOnline = true }) {
  const [paymentMethodId, setPaymentMethodId] = useState(null);
  const [paymentMethods, setPaymentMethods] = useState([]);
  const [customLegend, setCustomLegend] = useState('');
  const [amountReceivedInput, setAmountReceivedInput] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [ticketConfig, setTicketConfig] = useState(null);
  const ticketRef = useRef(null);

  const [applyDiscount, setApplyDiscount] = useState(false);
  const [discountMode, setDiscountMode] = useState('direct');
  const [discountSubType, setDiscountSubType] = useState('fixed');
  const [discountValue, setDiscountValue] = useState(null);
  const [couponQuery, setCouponQuery] = useState('');
  const [couponSuggestions, setCouponSuggestions] = useState([]);
  const [selectedCoupon, setSelectedCoupon] = useState(null);

  useEffect(() => {
    if (visible) {
      setCustomLegend('');
      setAmountReceivedInput('');
      setApplyDiscount(false);
      setDiscountMode('direct');
      setDiscountSubType('fixed');
      setDiscountValue(null);
      setCouponQuery('');
      setSelectedCoupon(null);
      Promise.all([
        api.get('/ticket-configs/active'),
        api.get('/payment-methods', { params: { status: 'active' } }),
      ]).then(([configRes, pmRes]) => {
        setTicketConfig(configRes.data.data);
        const methods = pmRes.data.data;
        setPaymentMethods(methods);
        if (methods.length > 0 && !paymentMethodId) {
          setPaymentMethodId(methods[0].id);
        }
      }).catch(() => {
        setTicketConfig(null);
      });
    }
  }, [visible]);

  const totalGrossBefore = cart.reduce((sum, i) => sum + (i.sale_price * i.quantity) - i.discount, 0);

  const computedDiscount = useMemo(() => {
    if (!applyDiscount) return 0;

    if (discountMode === 'direct') {
      if (discountSubType === 'fixed' && discountValue > 0) {
        return Math.min(discountValue, totalGrossBefore);
      }
      if (discountSubType === 'percentage' && discountValue > 0) {
        return Math.min(Math.round(totalGrossBefore * (discountValue / 100) * 100) / 100, totalGrossBefore);
      }
    }

    if (discountMode === 'coupon' && selectedCoupon) {
      if (selectedCoupon.type === 'percentage') {
        return Math.min(Math.round(totalGrossBefore * (parseFloat(selectedCoupon.value) / 100) * 100) / 100, totalGrossBefore);
      }
      if (selectedCoupon.type === 'fixed_amount') {
        return Math.min(parseFloat(selectedCoupon.value), totalGrossBefore);
      }
      if (selectedCoupon.type === 'freebie_100') {
        return totalGrossBefore;
      }
    }

    return 0;
  }, [applyDiscount, discountMode, discountSubType, discountValue, selectedCoupon, totalGrossBefore]);

  const totalGross = totalGrossBefore - computedDiscount;
  const subtotal = totalGross / (1 + taxRate);
  const ivaTotal = totalGross - subtotal;
  const total = totalGross;

  const selectedMethod = paymentMethods.find(pm => pm.id === paymentMethodId);
  const isCash = selectedMethod?.slug === 'cash';

  // Todo el calculo de efectivo es sincrono y derivado del texto del input:
  // se recalcula en el mismo render de cada tecla, sin esperar al blur.
  const amountReceived = parseAmount(amountReceivedInput);
  const totalCents = Math.round(total * 100);
  const receivedCents = amountReceived == null ? null : Math.round(amountReceived * 100);
  const amountChange = isCash && receivedCents != null
    ? Math.max(0, receivedCents - totalCents) / 100
    : 0;
  const amountMissing = isCash && receivedCents != null
    ? Math.max(0, totalCents - receivedCents) / 100
    : 0;
  const cashInsufficient = isCash && (receivedCents == null || receivedCents < totalCents);

  const discountType = useMemo(() => {
    if (!applyDiscount || computedDiscount === 0) return 'none';
    if (discountMode === 'direct') return discountSubType;
    if (discountMode === 'coupon' && selectedCoupon) {
      if (selectedCoupon.type === 'percentage') return 'percentage';
      return 'fixed';
    }
    return 'none';
  }, [applyDiscount, computedDiscount, discountMode, discountSubType, selectedCoupon]);

  const discountValueForPayload = useMemo(() => {
    if (!applyDiscount || computedDiscount === 0) return 0;
    if (discountMode === 'direct') return discountValue || 0;
    if (discountMode === 'coupon' && selectedCoupon) return parseFloat(selectedCoupon.value);
    return 0;
  }, [applyDiscount, computedDiscount, discountMode, discountValue, selectedCoupon]);

  const previewOrder = useMemo(() => ({
    items: cart,
    subtotal,
    iva_total: ivaTotal,
    total,
    discount_total: computedDiscount,
    amount_received: isCash ? amountReceived : null,
    amount_change: isCash ? amountChange : null,
    payment_method: selectedMethod || { name: 'N/A', slug: '' },
    created_at: new Date().toISOString(),
  }), [cart, subtotal, ivaTotal, total, computedDiscount, amountReceived, amountChange, isCash, selectedMethod]);

  const searchCoupons = async (e) => {
    try {
      const res = await api.get('/promotions/search', { params: { query: e.query } });
      setCouponSuggestions(res.data.data);
    } catch {
      setCouponSuggestions([]);
    }
  };

  const handleSubmit = async () => {
    if (!ticketConfig) {
      toast.error('No hay configuracion de ticket activa.');
      return;
    }

    if (isCash && cashInsufficient) {
      toast.error('El dinero recibido es insuficiente.');
      return;
    }

    setSubmitting(true);

    const payload = {
      payment_method_id: paymentMethodId,
      custom_legend: customLegend || null,
      amount_received: isCash ? Math.round(amountReceived * 100) / 100 : total,
      amount_change: isCash ? Math.round(amountChange * 100) / 100 : 0,
      discount_type: discountType,
      discount_value: discountValueForPayload,
      promotion_id: (applyDiscount && discountMode === 'coupon' && selectedCoupon) ? selectedCoupon.id : null,
      items: cart.map(i => ({
        product_id: i.product_id,
        quantity: i.quantity,
        promotion_id: i.promotion_id,
      })),
    };

    if (!isOnline) {
      const offlineOrder = {
        ...previewOrder,
        id: crypto.randomUUID(),
        folio: crypto.randomUUID().substring(0, 8).toUpperCase(),
        _offline: true,
      };

      addToOfflineQueue(payload);

      toast.info('Orden guardada localmente', {
        description: 'Se sincronizara automaticamente al recuperar conexion.',
      });

      onSuccess?.(offlineOrder, { printerData: null, ticketConfig, offline: true });
      onHide();
      setSubmitting(false);
      return;
    }

    try {
      const res = await api.post('/orders', payload);
      const order = res.data.data;
      const printerData = res.data.printer_data;

      toast.success('Venta procesada con exito!');

      // Sin impresion automatica: la decision de imprimir se delega al
      // PrintConfirmationModal que POSPage abre con estos datos.
      onSuccess?.(order, { printerData, ticketConfig });

      onHide();
    } catch (err) {
      const data = err.response?.data;
      if (!err.response && !navigator.onLine) {
        const offlineOrder = {
          ...previewOrder,
          id: crypto.randomUUID(),
          folio: crypto.randomUUID().substring(0, 8).toUpperCase(),
          _offline: true,
        };

        addToOfflineQueue(payload);

        toast.info('Sin conexion — orden guardada localmente', {
          description: 'Se sincronizara automaticamente al recuperar conexion.',
        });

        onSuccess?.(offlineOrder, { printerData: null, ticketConfig, offline: true });
        onHide();
      } else if (data?.code === 'ERR_POS_CASH_REGISTER_REQUIRED') {
        toast.error('Caja no abierta', { description: data.message });
      } else if (data?.code === 'ERR_POS_PROMOTION_LIMIT_EXCEEDED') {
        toast.error('Limite de promociones excedido', { description: data.message });
      } else if (data?.code === 'ERR_TICKET_NO_ACTIVE_CONFIG') {
        toast.error('Sin configuracion de ticket', { description: data.message });
      } else {
        toast.error(data?.message || 'Error al procesar la orden.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const paymentMethodOptions = paymentMethods.map(pm => ({ label: pm.name, value: pm.id }));

  return (
    <Dialog
      visible={visible}
      onHide={onHide}
      closable={!submitting}
      dismissableMask={!submitting}
      modal
      header={null}
      className="w-full max-w-3xl"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30' },
        root: { className: 'rounded-2xl border-0 shadow-2xl' },
        content: { className: 'p-0' },
      }}
    >
      <div className="p-6">
        <div className="mb-5 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50">
            <svg className="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18.75 12h.008v.008h-.008V12Zm-12 0h.008v.008H6.75V12Z" />
            </svg>
          </div>
          <div>
            <h3 className="text-lg font-semibold text-slate-900">Confirmar Cobro</h3>
            <p className="text-xs text-slate-500">
              Revisa la previsualizacion del ticket antes de confirmar.
            </p>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 px-6 pb-6 lg:grid-cols-2">
        {/* Left: Controls */}
        <div className="space-y-4">
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Metodo de Pago</label>
            <Dropdown
              value={paymentMethodId}
              options={paymentMethodOptions}
              onChange={(e) => {
                setPaymentMethodId(e.value);
                // Al salir de efectivo el monto recibido deja de aplicar.
                if (paymentMethods.find(pm => pm.id === e.value)?.slug !== 'cash') {
                  setAmountReceivedInput('');
                }
              }}
              disabled={submitting}
              className="w-full text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
          </div>

          {/* Cash received input — only for efectivo */}
          {isCash && (
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">
                Dinero Recibido ($) *
              </label>
              <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-lg font-semibold text-slate-400">
                  $
                </span>
                <input
                  type="text"
                  inputMode="decimal"
                  autoComplete="off"
                  autoFocus
                  value={amountReceivedInput}
                  // Sanitizacion en el propio onChange: el caracter invalido nunca
                  // llega al estado, y el cambio se recalcula en el mismo render.
                  onChange={(e) => setAmountReceivedInput(sanitizeAmount(e.target.value))}
                  placeholder="0.00"
                  disabled={submitting}
                  className="w-full rounded-lg border border-slate-200 py-2.5 pl-8 pr-3 text-lg font-semibold text-center text-slate-900 placeholder-slate-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 disabled:opacity-50"
                />
              </div>

              {!cashInsufficient && amountReceived != null && (
                <div className="mt-2 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2.5 text-center">
                  <span className="text-xs text-emerald-600 font-medium">Cambio a devolver</span>
                  <p className="text-2xl font-bold text-emerald-700">
                    ${amountChange.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                  </p>
                </div>
              )}

              {cashInsufficient && amountReceived != null && (
                <div className="mt-2 rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-xs text-rose-700 font-medium text-center">
                  El monto recibido es menor al total. Faltan ${amountMissing.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                </div>
              )}
            </div>
          )}

          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
              Leyenda Personalizada
              <span className="ml-1 text-xs text-slate-400">(opcional)</span>
            </label>
            <textarea
              value={customLegend}
              onChange={(e) => setCustomLegend(e.target.value)}
              placeholder="Escribe una leyenda que aparecera en el ticket impreso..."
              disabled={submitting}
              rows={3}
              maxLength={500}
              className="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:opacity-50 resize-none"
            />
            <p className="mt-1 text-right text-xs text-slate-400">{customLegend.length}/500</p>
          </div>

          {/* Discount / Coupon Section */}
          <div className="rounded-lg border border-slate-200 p-3">
            <div className="flex items-center gap-2">
              <Checkbox
                inputId="applyDiscount"
                checked={applyDiscount}
                onChange={(e) => {
                  setApplyDiscount(e.checked);
                  if (!e.checked) {
                    setDiscountValue(null);
                    setSelectedCoupon(null);
                    setCouponQuery('');
                  }
                }}
                disabled={submitting}
              />
              <label htmlFor="applyDiscount" className="text-sm font-medium text-slate-700 cursor-pointer select-none">
                Aplicar descuento o cupon?
              </label>
            </div>

            {applyDiscount && (
              <div className="mt-3 space-y-3">
                <div className="flex gap-4">
                  <div className="flex items-center gap-2">
                    <RadioButton
                      inputId="modeDirect"
                      name="discountMode"
                      value="direct"
                      onChange={(e) => { setDiscountMode(e.value); setSelectedCoupon(null); setCouponQuery(''); }}
                      checked={discountMode === 'direct'}
                      disabled={submitting}
                    />
                    <label htmlFor="modeDirect" className="text-xs font-medium text-slate-600 cursor-pointer">
                      Descuento Directo
                    </label>
                  </div>
                  <div className="flex items-center gap-2">
                    <RadioButton
                      inputId="modeCoupon"
                      name="discountMode"
                      value="coupon"
                      onChange={(e) => { setDiscountMode(e.value); setDiscountValue(null); }}
                      checked={discountMode === 'coupon'}
                      disabled={submitting}
                    />
                    <label htmlFor="modeCoupon" className="text-xs font-medium text-slate-600 cursor-pointer">
                      Cupon Activo
                    </label>
                  </div>
                </div>

                {discountMode === 'direct' && (
                  <div className="flex items-end gap-2">
                    <div className="flex-1">
                      <InputNumber
                        value={discountValue}
                        onValueChange={(e) => setDiscountValue(e.value)}
                        min={0}
                        max={discountSubType === 'percentage' ? 100 : 9999999}
                        minFractionDigits={2}
                        maxFractionDigits={2}
                        disabled={submitting}
                        placeholder={discountSubType === 'fixed' ? 'Monto $' : 'Porcentaje %'}
                        className="w-full"
                        inputClassName="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                        pt={{ root: { className: 'w-full' } }}
                      />
                    </div>
                    <Dropdown
                      value={discountSubType}
                      options={[
                        { label: '$ Fijo', value: 'fixed' },
                        { label: '% Porcentaje', value: 'percentage' },
                      ]}
                      onChange={(e) => { setDiscountSubType(e.value); setDiscountValue(null); }}
                      disabled={submitting}
                      className="w-36 text-sm"
                      pt={{ root: { className: 'w-36' } }}
                    />
                  </div>
                )}

                {discountMode === 'coupon' && (
                  <AutoComplete
                    value={selectedCoupon}
                    suggestions={couponSuggestions}
                    completeMethod={searchCoupons}
                    field="name"
                    onChange={(e) => {
                      if (typeof e.value === 'string') {
                        setCouponQuery(e.value);
                        setSelectedCoupon(null);
                      } else {
                        setSelectedCoupon(e.value);
                        setCouponQuery('');
                      }
                    }}
                    placeholder="Buscar cupon por nombre..."
                    disabled={submitting}
                    className="w-full"
                    inputClassName="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                    pt={{ root: { className: 'w-full' } }}
                    itemTemplate={(item) => (
                      <div className="flex items-center justify-between px-2 py-1">
                        <span className="text-sm font-medium text-slate-800">{item.name}</span>
                        <span className="ml-2 rounded bg-indigo-50 px-1.5 py-0.5 text-xs font-semibold text-indigo-700">
                          {item.type === 'percentage' ? `${item.value}%` : item.type === 'freebie_100' ? '100%' : `$${parseFloat(item.value).toFixed(2)}`}
                        </span>
                      </div>
                    )}
                  />
                )}

                {computedDiscount > 0 && (
                  <div className="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-center">
                    <span className="text-xs text-amber-700 font-medium">Descuento aplicado</span>
                    <p className="text-lg font-bold text-amber-800">
                      -${computedDiscount.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                    </p>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Totals breakdown */}
          <div className="rounded-lg bg-slate-50 p-3 text-sm">
            <div className="flex justify-between text-slate-600">
              <span>Subtotal (bruto)</span>
              <span>${totalGrossBefore.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
            </div>
            {computedDiscount > 0 && (
              <div className="flex justify-between text-amber-700 font-medium">
                <span>Descuento</span>
                <span>-${computedDiscount.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
              </div>
            )}
            <div className="flex justify-between text-slate-600">
              <span>Subtotal Neto</span>
              <span>${subtotal.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
            </div>
            <div className="flex justify-between text-slate-600">
              <span>IVA ({(taxRate * 100).toFixed(0)}%)</span>
              <span>${ivaTotal.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
            </div>
            <div className="mt-2 flex justify-between border-t border-slate-200 pt-2 text-lg font-bold text-slate-900">
              <span>Total</span>
              <span>${total.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
            </div>
          </div>

          {!ticketConfig && (
            <div className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 font-medium">
              No hay configuracion de ticket activa. Ve a Configuracion para crear una.
            </div>
          )}

          <div className="flex gap-3">
            <Button
              type="button"
              label="Cancelar"
              onClick={onHide}
              disabled={submitting}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              type="button"
              label={submitting ? 'Procesando...' : 'Confirmar Cobro'}
              onClick={handleSubmit}
              disabled={submitting || !ticketConfig || !paymentMethodId || (isCash && cashInsufficient)}
              loading={submitting}
              className="flex-1 cursor-pointer rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </div>

        {/* Right: Ticket preview */}
        <div className="flex justify-center">
          <TicketPreview
            ref={ticketRef}
            order={previewOrder}
            ticketConfig={ticketConfig}
            customLegend={customLegend}
            taxRate={taxRate}
          />
        </div>
      </div>
    </Dialog>
  );
}
