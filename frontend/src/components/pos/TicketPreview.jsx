import { forwardRef } from 'react';

const LINE_WIDTH = 32;
const SEP_DOUBLE = '='.repeat(LINE_WIDTH);
const SEP_SINGLE = '-'.repeat(LINE_WIDTH);

function formatMoney(val) {
  return '$' + parseFloat(val).toFixed(2);
}

function padLine(left, right) {
  const gap = LINE_WIDTH - left.length - right.length;
  if (gap < 1) return left.substring(0, LINE_WIDTH - right.length - 1) + ' ' + right;
  return left + ' '.repeat(gap) + right;
}

function truncate(str, max) {
  if (str.length <= max) return str;
  return str.substring(0, max - 1) + '.';
}

const monoStyle = {
  fontFamily: "'Courier New', Courier, monospace",
  fontSize: '12px',
  lineHeight: '1.3',
  fontWeight: 600,
  color: '#000',
  WebkitFontSmoothing: 'none',
  MozOsxFontSmoothing: 'unset',
};

const TicketPreview = forwardRef(function TicketPreview({ order, ticketConfig, customLegend, taxRate = 0.16 }, ref) {
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

  const dateStr = createdAt.toLocaleString('es-MX', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });

  const maxNameLen = LINE_WIDTH - 10;

  return (
    <div
      ref={ref}
      id="ticket-print-area"
      style={{
        width: '48mm',
        maxWidth: '58mm',
        margin: '0 auto',
        padding: 0,
        backgroundColor: '#fff',
        ...monoStyle,
      }}
    >
      <div
        className="ticket-inner-screen"
        style={{
          border: '2px dashed #cbd5e1',
          borderRadius: '8px',
          padding: '4mm 3mm',
        }}
      >
        {/* Header */}
        <div style={{ textAlign: 'center', marginBottom: '2mm' }}>
          <div style={{ fontSize: '13px', fontWeight: 700, letterSpacing: '0.5px' }}>
            {ticketConfig.business_name}
          </div>
          {ticketConfig.rfc && (
            <div style={{ fontSize: '11px' }}>RFC: {ticketConfig.rfc}</div>
          )}
          {ticketConfig.address && (
            <div style={{ fontSize: '10px', wordWrap: 'break-word' }}>{ticketConfig.address}</div>
          )}
          {ticketConfig.phone && (
            <div style={{ fontSize: '11px' }}>Tel: {ticketConfig.phone}</div>
          )}
          {ticketConfig.header_message && (
            <div style={{ fontSize: '10px', fontStyle: 'italic', marginTop: '1mm' }}>
              {ticketConfig.header_message}
            </div>
          )}
        </div>

        <pre style={{ margin: 0, ...monoStyle, textAlign: 'center' }}>{SEP_DOUBLE}</pre>

        {/* Order info */}
        <div style={{ margin: '1mm 0' }}>
          {orderId && (
            <pre style={{ margin: 0, ...monoStyle }}>
              {padLine('Folio:', orderId.substring(0, 8).toUpperCase())}
            </pre>
          )}
          <pre style={{ margin: 0, ...monoStyle }}>
            {padLine('Fecha:', dateStr)}
          </pre>
          <pre style={{ margin: 0, ...monoStyle, whiteSpace: 'nowrap' }}>
            {padLine('Pago:', paymentLabel)}
          </pre>
        </div>

        <pre style={{ margin: 0, ...monoStyle, textAlign: 'center' }}>{SEP_SINGLE}</pre>

        {/* Column headers */}
        <pre style={{ margin: '1mm 0 0 0', ...monoStyle, fontWeight: 700 }}>
          {padLine('PRODUCTO', 'IMPORTE')}
        </pre>

        <pre style={{ margin: 0, ...monoStyle, textAlign: 'center' }}>{SEP_SINGLE}</pre>

        {/* Items */}
        <div style={{ margin: '1mm 0' }}>
          {items.map((item, i) => {
            const productName = item.product?.name || item.product_name || 'Producto';
            const qty = item.quantity;
            const basePrice = parseFloat(item.base_price_at_sale ?? item.sale_price ?? 0);
            const discount = parseFloat(item.discount_amount_at_sale ?? item.discount ?? 0);
            const finalPrice = parseFloat(item.final_price_at_sale ?? (basePrice * qty - discount));

            return (
              <div key={i} style={{ marginBottom: '1mm' }}>
                <pre style={{ margin: 0, ...monoStyle }}>
                  {padLine(truncate(productName, maxNameLen), formatMoney(finalPrice))}
                </pre>
                <pre style={{ margin: 0, ...monoStyle, fontSize: '10px', color: '#666' }}>
                  {'  ' + qty + ' x ' + formatMoney(basePrice)}
                  {discount > 0 ? '  -' + formatMoney(discount) : ''}
                </pre>
                {(item.promotion || item.promotion_name) && (
                  <pre style={{ margin: 0, ...monoStyle, fontSize: '10px', fontStyle: 'italic', color: '#059669' }}>
                    {'  ' + truncate(item.promotion?.name || item.promotion_name, LINE_WIDTH - 2)}
                  </pre>
                )}
              </div>
            );
          })}
        </div>

        <pre style={{ margin: 0, ...monoStyle, textAlign: 'center' }}>{SEP_SINGLE}</pre>

        {/* Totals */}
        <div style={{ margin: '1mm 0' }}>
          <pre style={{ margin: 0, ...monoStyle }}>
            {padLine('Subtotal:', formatMoney(subtotal))}
          </pre>
          <pre style={{ margin: 0, ...monoStyle }}>
            {padLine(`IVA (${(taxRate * 100).toFixed(0)}%):`, formatMoney(ivaTotal))}
          </pre>
          <pre style={{ margin: 0, ...monoStyle, fontWeight: 700, fontSize: '13px' }}>
            {padLine('TOTAL:', formatMoney(total))}
          </pre>
        </div>

        {/* Custom legend */}
        {legend && (
          <>
            <pre style={{ margin: 0, ...monoStyle, textAlign: 'center' }}>{SEP_SINGLE}</pre>
            <div style={{ textAlign: 'center', fontSize: '10px', margin: '1mm 0', wordWrap: 'break-word', whiteSpace: 'pre-wrap' }}>
              {legend}
            </div>
          </>
        )}

        <pre style={{ margin: 0, ...monoStyle, textAlign: 'center' }}>{SEP_DOUBLE}</pre>

        {/* Footer */}
        <div style={{ textAlign: 'center', marginTop: '1mm', fontSize: '10px' }}>
          {ticketConfig.footer_message && (
            <div style={{ fontStyle: 'italic', marginBottom: '1mm' }}>
              {ticketConfig.footer_message}
            </div>
          )}
          <div style={{ color: '#999' }}>v{ticketConfig.version} - Cronos POS</div>
        </div>
      </div>
    </div>
  );
});

export default TicketPreview;
