import { useState, useEffect, useRef, useCallback } from 'react';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import TicketPreview from './TicketPreview';

export default function CheckoutModal({ visible, onHide, cart, onSuccess }) {
  const [paymentMethodId, setPaymentMethodId] = useState(null);
  const [paymentMethods, setPaymentMethods] = useState([]);
  const [customLegend, setCustomLegend] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [ticketConfig, setTicketConfig] = useState(null);
  const [completedOrder, setCompletedOrder] = useState(null);
  const ticketRef = useRef(null);

  useEffect(() => {
    if (visible) {
      setCustomLegend('');
      setCompletedOrder(null);
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

  const subtotal = cart.reduce((sum, i) => sum + (i.sale_price * i.quantity) - i.discount, 0);
  const ivaTotal = subtotal * 0.16;
  const total = subtotal + ivaTotal;

  const selectedMethod = paymentMethods.find(pm => pm.id === paymentMethodId);

  const previewOrder = {
    items: cart,
    subtotal,
    iva_total: ivaTotal,
    total,
    payment_method: selectedMethod || { name: 'N/A', slug: '' },
    created_at: new Date().toISOString(),
  };

  const handleSubmit = async () => {
    if (!ticketConfig) {
      toast.error('No hay configuracion de ticket activa.');
      return;
    }

    setSubmitting(true);
    try {
      const payload = {
        payment_method_id: paymentMethodId,
        custom_legend: customLegend || null,
        items: cart.map(i => ({
          product_id: i.product_id,
          quantity: i.quantity,
          promotion_id: i.promotion_id,
        })),
      };

      const res = await api.post('/orders', payload);
      const order = res.data.data;
      setCompletedOrder(order);
      toast.success('Orden registrada exitosamente.');
      onSuccess?.(order);
    } catch (err) {
      const data = err.response?.data;
      if (data?.code === 'ERR_POS_CASH_REGISTER_REQUIRED') {
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

  const handlePrint = useCallback(() => {
    window.print();
  }, []);

  const handleClose = () => {
    setCompletedOrder(null);
    onHide();
  };

  const displayOrder = completedOrder || previewOrder;

  const paymentMethodOptions = paymentMethods.map(pm => ({ label: pm.name, value: pm.id }));

  return (
    <Dialog
      visible={visible}
      onHide={handleClose}
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
      <div className="p-6 print:hidden">
        <div className="mb-5 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50">
            <svg className="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18.75 12h.008v.008h-.008V12Zm-12 0h.008v.008H6.75V12Z" />
            </svg>
          </div>
          <div>
            <h3 className="text-lg font-semibold text-slate-900">
              {completedOrder ? 'Orden Completada' : 'Confirmar Cobro'}
            </h3>
            <p className="text-xs text-slate-500">
              {completedOrder
                ? 'Puedes imprimir el ticket o cerrar esta ventana.'
                : 'Revisa la previsualizacion del ticket antes de confirmar.'}
            </p>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 px-6 pb-6 lg:grid-cols-2 print:block">
        {/* Left: Controls */}
        <div className="space-y-4 print:hidden">
          {!completedOrder && (
            <>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Metodo de Pago</label>
                <Dropdown
                  value={paymentMethodId}
                  options={paymentMethodOptions}
                  onChange={(e) => setPaymentMethodId(e.value)}
                  disabled={submitting}
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>

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

              <div className="rounded-lg bg-slate-50 p-3 text-sm">
                <div className="flex justify-between text-slate-600">
                  <span>Subtotal</span>
                  <span>${subtotal.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                </div>
                <div className="flex justify-between text-slate-600">
                  <span>IVA (16%)</span>
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
                  onClick={handleClose}
                  disabled={submitting}
                  className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                  pt={{ root: { className: 'border border-slate-200' } }}
                />
                <Button
                  type="button"
                  label={submitting ? 'Procesando...' : 'Confirmar Cobro'}
                  onClick={handleSubmit}
                  disabled={submitting || !ticketConfig || !paymentMethodId}
                  loading={submitting}
                  className="flex-1 cursor-pointer rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                  pt={{ root: { className: 'border-0' } }}
                />
              </div>
            </>
          )}

          {completedOrder && (
            <div className="flex gap-3">
              <Button
                type="button"
                label="Cerrar"
                onClick={handleClose}
                className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                pt={{ root: { className: 'border border-slate-200' } }}
              />
              <Button
                type="button"
                label="Imprimir Ticket"
                onClick={handlePrint}
                className="flex-1 cursor-pointer rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500"
                pt={{ root: { className: 'border-0' } }}
              />
            </div>
          )}
        </div>

        {/* Right: Ticket preview */}
        <div className="flex justify-center print:block">
          <TicketPreview
            ref={ticketRef}
            order={displayOrder}
            ticketConfig={ticketConfig}
            customLegend={customLegend}
          />
        </div>
      </div>
    </Dialog>
  );
}
