import { useState } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';

const fmt = (v) => `$${Number(v || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

function formatDate(value) {
  const date = value ? new Date(value) : new Date();
  return date.toLocaleString('es-MX', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

/**
 * Modal de confirmacion de impresion post-venta.
 *
 * Se abre DESPUES de que la venta se registro con exito. No dispara ninguna
 * impresion automatica: el usuario decide si envia el ticket al agente local
 * ("Imprimir Ticket") o si continua sin imprimir ("Omitir / No imprimir").
 * La opcion de omitir cierra el modal sin realizar NINGUNA peticion HTTP al
 * agente y deja el POS listo para la siguiente venta.
 */
export default function PrintConfirmationModal({
  visible,
  order,
  ticketConfig,
  printerData,
  taxRate = 0.16,
  cronosAgent,
  onClose,
}) {
  const [printing, setPrinting] = useState(false);
  const [printed, setPrinted] = useState(false);

  if (!order) return null;

  const items = order.items || [];
  const folio = order.folio || (order.id ? String(order.id).substring(0, 8).toUpperCase() : '—');
  const paymentMethod = order.payment_method;
  const paymentLabel = typeof paymentMethod === 'object'
    ? (paymentMethod?.name || 'N/A')
    : (paymentMethod || 'N/A');

  const discountTotal = parseFloat(order.discount_total ?? 0);
  const amountReceived = order.amount_received != null ? parseFloat(order.amount_received) : null;
  const amountChange = order.amount_change != null ? parseFloat(order.amount_change) : null;
  const showCashChange = amountReceived != null && amountReceived > 0;

  const printerName = cronosAgent?.printerName || '';
  const thankYouMessage = ticketConfig?.footer_message || '¡Gracias por su compra!';

  const handleClose = () => {
    setPrinting(false);
    setPrinted(false);
    onClose?.();
  };

  // Unico punto del flujo post-venta que habla con el agente local
  const handlePrint = async () => {
    if (!printerData) {
      toast.error('No se generaron datos de impresion para este ticket.');
      return;
    }
    if (!printerName) {
      toast.error('No hay impresora configurada.', {
        description: 'Ve a Configuracion del Sistema y detecta el agente local para elegir una impresora.',
      });
      return;
    }

    setPrinting(true);
    try {
      await cronosAgent.printTicket(printerName, printerData);
      setPrinted(true);
      toast.success('Ticket enviado a la impresora.');
      handleClose();
    } catch (err) {
      toast.error(`No se pudo enviar a la ticketera: ${err.message || err}`);
    } finally {
      setPrinting(false);
    }
  };

  return (
    <Dialog
      visible={visible}
      onHide={handleClose}
      closable={!printing}
      dismissableMask={false}
      closeOnEscape={!printing}
      modal
      header={null}
      className="w-full max-w-lg"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30' },
        root: { className: 'rounded-2xl border-0 shadow-2xl' },
        content: { className: 'p-0' },
      }}
    >
      <div className="p-6">
        {/* Encabezado */}
        <div className="mb-5 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50">
            <i className="pi pi-check-circle text-lg text-emerald-600" />
          </div>
          <div className="flex-1">
            <h3 className="text-lg font-semibold text-slate-900">Venta registrada</h3>
            <p className="text-xs text-slate-500">Revisa el ticket y decide si deseas imprimirlo.</p>
          </div>
          {printerName ? (
            <Tag value={printerName} severity="info" className="text-[10px] font-mono" />
          ) : (
            <Tag value="Sin impresora" severity="warning" className="text-[10px]" />
          )}
        </div>

        {/* Resumen visual del ticket */}
        <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50/60 p-4">
          <div className="text-center">
            <p className="text-sm font-semibold text-slate-900">
              {ticketConfig?.business_name || 'Cronos POS'}
            </p>
            {ticketConfig?.rfc && (
              <p className="text-[11px] text-slate-500">RFC: {ticketConfig.rfc}</p>
            )}
          </div>

          <div className="my-3 border-t border-dashed border-slate-300" />

          <div className="space-y-1 text-xs">
            <div className="flex justify-between">
              <span className="text-slate-500">Folio</span>
              <span className="font-mono font-semibold text-slate-900">{folio}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-slate-500">Fecha</span>
              <span className="text-slate-700">{formatDate(order.created_at)}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-slate-500">Metodo de pago</span>
              <span className="font-semibold text-slate-900 uppercase">{paymentLabel}</span>
            </div>
          </div>

          <div className="my-3 border-t border-dashed border-slate-300" />

          {/* Productos */}
          <ul className="max-h-52 space-y-2 overflow-y-auto pr-1">
            {items.map((item, i) => {
              const name = item.product?.name || item.product_name || 'Producto';
              const qty = item.quantity;
              const basePrice = parseFloat(item.base_price_at_sale ?? item.sale_price ?? 0);
              const itemDiscount = parseFloat(item.discount_amount_at_sale ?? item.discount ?? 0);
              const lineTotal = parseFloat(item.final_price_at_sale ?? (basePrice * qty - itemDiscount));

              return (
                <li key={i} className="flex items-start justify-between gap-3 text-xs">
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium text-slate-800">{name}</p>
                    <p className="text-[11px] text-slate-500">
                      {qty} x {fmt(basePrice)}
                      {itemDiscount > 0 && (
                        <span className="ml-1 text-amber-600">-{fmt(itemDiscount)}</span>
                      )}
                    </p>
                    {(item.promotion?.name || item.promotion_name) && (
                      <p className="text-[11px] italic text-emerald-600">
                        {item.promotion?.name || item.promotion_name}
                      </p>
                    )}
                  </div>
                  <span className="shrink-0 font-semibold text-slate-900">{fmt(lineTotal)}</span>
                </li>
              );
            })}
            {items.length === 0 && (
              <li className="py-2 text-center text-xs text-slate-400">Ticket sin partidas.</li>
            )}
          </ul>

          <div className="my-3 border-t border-dashed border-slate-300" />

          {/* Totales */}
          <div className="space-y-1 text-xs">
            {discountTotal > 0 && (
              <div className="flex justify-between font-medium text-amber-700">
                <span>Descuento</span>
                <span>-{fmt(discountTotal)}</span>
              </div>
            )}
            <div className="flex justify-between text-slate-600">
              <span>Subtotal</span>
              <span>{fmt(order.subtotal)}</span>
            </div>
            <div className="flex justify-between text-slate-600">
              <span>IVA ({(taxRate * 100).toFixed(0)}%)</span>
              <span>{fmt(order.iva_total)}</span>
            </div>
            <div className="flex justify-between border-t border-slate-200 pt-1.5 text-base font-bold text-slate-900">
              <span>Total</span>
              <span>{fmt(order.total)}</span>
            </div>
            {showCashChange && (
              <>
                <div className="flex justify-between text-slate-600">
                  <span>Recibido</span>
                  <span>{fmt(amountReceived)}</span>
                </div>
                <div className="flex justify-between font-semibold text-emerald-700">
                  <span>Cambio</span>
                  <span>{fmt(amountChange)}</span>
                </div>
              </>
            )}
          </div>

          <div className="my-3 border-t border-dashed border-slate-300" />

          <p className="text-center text-xs font-medium italic text-slate-600">
            {thankYouMessage}
          </p>
        </div>

        {!printerData && (
          <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            <i className="pi pi-exclamation-triangle mr-1.5" />
            Esta venta no genero datos de impresion. Puedes reimprimirla mas tarde desde el Historial de Ventas.
          </div>
        )}

        {/* Acciones */}
        <div className="mt-5 flex gap-3">
          <Button
            type="button"
            label="Omitir / No imprimir"
            icon="pi pi-times"
            onClick={handleClose}
            disabled={printing}
            className="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
            pt={{ root: { className: 'border border-slate-200' } }}
          />
          <Button
            type="button"
            label={printing ? 'Enviando...' : 'Imprimir Ticket'}
            icon="pi pi-print"
            onClick={handlePrint}
            disabled={printing || printed || !printerData}
            loading={printing}
            className="flex-1 cursor-pointer rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
            pt={{ root: { className: 'border-0' } }}
          />
        </div>

        <p className="mt-3 text-center text-[11px] text-slate-400">
          Omitir no realiza ninguna peticion al agente local. El ticket queda disponible para reimpresion desde el Historial de Ventas.
        </p>
      </div>
    </Dialog>
  );
}
