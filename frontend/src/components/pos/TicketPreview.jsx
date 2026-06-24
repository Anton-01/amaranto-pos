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
  lineHeight: '1.1',
  fontWeight: 600,
  color: '#000',
  WebkitFontSmoothing: 'none',
  MozOsxFontSmoothing: 'unset',
};

const preStyle = { margin: 0, padding: 0, ...monoStyle };

const TicketPreview = forwardRef(function TicketPreview({ order, ticketConfig, customLegend, taxRate = 0.16 }, ref) {
  if (!ticketConfig) return null;

  const items = order?.items || [];
  const subtotal = order?.subtotal ?? 0;
  const ivaTotal = order?.iva_total ?? 0;
  const total = order?.total ?? 0;
  const amountReceived = order?.amount_received ?? null;
  const amountChange = order?.amount_change ?? null;
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
  const showCashChange = amountReceived != null && parseFloat(amountReceived) > 0;

  return (
    <div
      ref={ref}
      id="ticket-print-area"
      style={{
        boxSizing: 'border-box',
        width: '100%',
        maxWidth: '240px',
        margin: '0 auto',
        padding: 0,
        backgroundColor: '#fff',
        ...monoStyle,
      }}
    >
      <div
        className="ticket-inner-screen"
        style={{
          boxSizing: 'border-box',
          border: '2px dashed #cbd5e1',
          borderRadius: '8px',
          padding: '4px',
          width: '100%',
          overflow: 'hidden',
        }}
      >
        {/* Header */}
        <div style={{ textAlign: 'center', marginBottom: '1px' }}>
          <div style={{ fontSize: '12px', fontWeight: 700, lineHeight: '1.1', wordWrap: 'break-word', whiteSpace: 'normal' }}>
            {ticketConfig.business_name}
          </div>
          {ticketConfig.rfc && (
            <div style={{ fontSize: '10px', lineHeight: '1.1' }}>RFC: {ticketConfig.rfc}</div>
          )}
          {ticketConfig.address && (
            <div style={{ fontSize: '9px', lineHeight: '1.1', wordWrap: 'break-word', whiteSpace: 'normal' }}>{ticketConfig.address}</div>
          )}
          {ticketConfig.phone && (
            <div style={{ fontSize: '10px', lineHeight: '1.1' }}>Tel: {ticketConfig.phone}</div>
          )}
          {ticketConfig.header_message && (
            <div style={{ fontSize: '9px', fontStyle: 'italic', lineHeight: '1.1', marginTop: '1px', wordWrap: 'break-word', whiteSpace: 'normal' }}>
              {ticketConfig.header_message}
            </div>
          )}
        </div>

        <pre style={preStyle}>{SEP_DOUBLE}</pre>

        {/* Order info */}
        <div style={{ margin: '1px 0' }}>
          {orderId && (
            <pre style={preStyle}>
              {padLine('Folio:', orderId.substring(0, 8).toUpperCase())}
            </pre>
          )}
          <pre style={preStyle}>
            {padLine('Fecha:', dateStr)}
          </pre>
          <pre style={preStyle}>
            {padLine('Pago:', paymentLabel)}
          </pre>
        </div>

        <pre style={preStyle}>{SEP_SINGLE}</pre>

        {/* Column headers */}
        <pre style={{ ...preStyle, fontWeight: 700 }}>
          {padLine('PRODUCTO', 'IMPORTE')}
        </pre>

        <pre style={preStyle}>{SEP_SINGLE}</pre>

        {/* Items */}
        <div style={{ margin: '1px 0' }}>
          {items.map((item, i) => {
            const productName = item.product?.name || item.product_name || 'Producto';
            const qty = item.quantity;
            const basePrice = parseFloat(item.base_price_at_sale ?? item.sale_price ?? 0);
            const discount = parseFloat(item.discount_amount_at_sale ?? item.discount ?? 0);
            const finalPrice = parseFloat(item.final_price_at_sale ?? (basePrice * qty - discount));

            return (
              <div key={i} style={{ marginBottom: '1px' }}>
                <pre style={preStyle}>
                  {padLine(truncate(productName, maxNameLen), formatMoney(finalPrice))}
                </pre>
                <pre style={{ ...preStyle, fontSize: '10px', color: '#666' }}>
                  {'  ' + qty + ' x ' + formatMoney(basePrice)}
                  {discount > 0 ? '  -' + formatMoney(discount) : ''}
                </pre>
                {(item.promotion || item.promotion_name) && (
                  <pre style={{ ...preStyle, fontSize: '10px', fontStyle: 'italic', color: '#059669' }}>
                    {'  ' + truncate(item.promotion?.name || item.promotion_name, LINE_WIDTH - 2)}
                  </pre>
                )}
              </div>
            );
          })}
        </div>

        <pre style={preStyle}>{SEP_SINGLE}</pre>

        {/* Totals */}
        <div style={{ margin: '1px 0' }}>
          <pre style={preStyle}>
            {padLine('Subtotal:', formatMoney(subtotal))}
          </pre>
          <pre style={preStyle}>
            {padLine(`IVA (${(taxRate * 100).toFixed(0)}%):`, formatMoney(ivaTotal))}
          </pre>
          <pre style={{ ...preStyle, fontWeight: 700 }}>
            {padLine('TOTAL:', formatMoney(total))}
          </pre>
          {showCashChange && (
            <>
              <pre style={preStyle}>{SEP_SINGLE}</pre>
              <pre style={preStyle}>
                {padLine('Recibido:', formatMoney(amountReceived))}
              </pre>
              <pre style={{ ...preStyle, fontWeight: 700 }}>
                {padLine('Cambio:', formatMoney(amountChange))}
              </pre>
            </>
          )}
        </div>

        {/* Custom legend */}
        {legend && (
          <>
            <pre style={preStyle}>{SEP_SINGLE}</pre>
            <div style={{ textAlign: 'center', fontSize: '9px', lineHeight: '1.1', margin: '1px 0', wordWrap: 'break-word', whiteSpace: 'pre-wrap' }}>
              {legend}
            </div>
          </>
        )}

        <pre style={preStyle}>{SEP_DOUBLE}</pre>

        {/* Footer */}
        <div style={{ textAlign: 'center', fontSize: '9px', lineHeight: '1.1' }}>
          {ticketConfig.footer_message && (
            <div style={{ fontStyle: 'italic', marginBottom: '1px', wordWrap: 'break-word', whiteSpace: 'normal' }}>
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
