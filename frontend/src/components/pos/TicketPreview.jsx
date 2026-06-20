import { forwardRef } from 'react';

const TicketPreview = forwardRef(function TicketPreview({ order, ticketConfig, customLegend }, ref) {
  if (!ticketConfig) return null;

  const items = order?.items || [];
  const subtotal = order?.subtotal ?? 0;
  const ivaTotal = order?.iva_total ?? 0;
  const total = order?.total ?? 0;
  const paymentMethod = order?.payment_method;
  const paymentLabel = typeof paymentMethod === 'object'
    ? (paymentMethod?.name || 'N/A').toUpperCase()
    : (paymentMethod || 'N/A').toUpperCase();
  const legend = customLegend ?? order?.custom_legend ?? '';
  const orderId = order?.id || '';
  const createdAt = order?.created_at ? new Date(order.created_at) : new Date();

  const sep = '='.repeat(40);
  const dash = '-'.repeat(40);

  return (
    <div ref={ref} className="ticket-preview mx-auto font-mono text-xs leading-relaxed" style={{ width: '302px' }}>
      <div className="rounded-lg border-2 border-dashed border-slate-300 bg-white px-5 py-6">
        {/* Header */}
        <div className="mb-3 text-center">
          <p className="text-sm font-bold tracking-widest">{ticketConfig.business_name}</p>
          {ticketConfig.rfc && <p className="text-slate-500">RFC: {ticketConfig.rfc}</p>}
          {ticketConfig.address && <p className="text-slate-500">{ticketConfig.address}</p>}
          {ticketConfig.phone && <p className="text-slate-500">Tel: {ticketConfig.phone}</p>}
          {ticketConfig.header_message && (
            <p className="mt-1 text-slate-600 italic">{ticketConfig.header_message}</p>
          )}
        </div>

        <p className="text-center text-slate-400 overflow-hidden whitespace-nowrap">{sep}</p>

        {/* Order info */}
        <div className="my-2 space-y-0.5">
          {orderId && (
            <div className="flex justify-between">
              <span className="text-slate-500">Folio:</span>
              <span className="font-semibold">{orderId.substring(0, 8).toUpperCase()}</span>
            </div>
          )}
          <div className="flex justify-between">
            <span className="text-slate-500">Fecha:</span>
            <span>{createdAt.toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Pago:</span>
            <span className="font-semibold">{paymentLabel}</span>
          </div>
        </div>

        <p className="text-center text-slate-400 overflow-hidden whitespace-nowrap">{dash}</p>

        {/* Items */}
        <div className="my-2 space-y-1.5">
          <div className="flex justify-between font-semibold text-slate-700">
            <span>PRODUCTO</span>
            <span>IMPORTE</span>
          </div>
          {items.map((item, i) => {
            const productName = item.product?.name || item.product_name || 'Producto';
            const qty = item.quantity;
            const basePrice = parseFloat(item.base_price_at_sale ?? item.sale_price ?? 0);
            const discount = parseFloat(item.discount_amount_at_sale ?? item.discount ?? 0);
            const finalPrice = parseFloat(item.final_price_at_sale ?? (basePrice * qty - discount));

            return (
              <div key={i}>
                <div className="flex justify-between">
                  <span className="truncate mr-2 text-slate-900">{productName}</span>
                  <span className="shrink-0 font-semibold">${finalPrice.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                </div>
                <div className="flex justify-between text-slate-500">
                  <span className="ml-2">{qty} x ${basePrice.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                  {discount > 0 && (
                    <span className="text-emerald-600">-${discount.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                  )}
                </div>
                {item.promotion && (
                  <p className="ml-2 text-emerald-600 italic">{item.promotion.name || item.promotion_name}</p>
                )}
                {!item.promotion && item.promotion_name && (
                  <p className="ml-2 text-emerald-600 italic">{item.promotion_name}</p>
                )}
              </div>
            );
          })}
        </div>

        <p className="text-center text-slate-400 overflow-hidden whitespace-nowrap">{dash}</p>

        {/* Totals */}
        <div className="my-2 space-y-0.5">
          <div className="flex justify-between text-slate-600">
            <span>Subtotal:</span>
            <span>${parseFloat(subtotal).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
          </div>
          <div className="flex justify-between text-slate-600">
            <span>IVA (16%):</span>
            <span>${parseFloat(ivaTotal).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
          </div>
          <div className="flex justify-between text-sm font-bold text-slate-900 pt-1">
            <span>TOTAL:</span>
            <span>${parseFloat(total).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
          </div>
        </div>

        {/* Custom legend */}
        {legend && (
          <>
            <p className="text-center text-slate-400 overflow-hidden whitespace-nowrap">{dash}</p>
            <p className="my-2 text-center text-slate-700 whitespace-pre-line break-words">{legend}</p>
          </>
        )}

        <p className="text-center text-slate-400 overflow-hidden whitespace-nowrap">{sep}</p>

        {/* Footer */}
        <div className="mt-3 text-center text-slate-400">
          {ticketConfig.footer_message && (
            <p className="mb-1 text-slate-600 italic">{ticketConfig.footer_message}</p>
          )}
          <p>v{ticketConfig.version} — Cronos POS</p>
        </div>
      </div>
    </div>
  );
});

export default TicketPreview;
