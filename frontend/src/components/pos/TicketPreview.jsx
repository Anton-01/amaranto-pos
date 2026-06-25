import { forwardRef } from 'react';

function fmt(val) {
  return '$' + parseFloat(val).toFixed(2);
}

function truncate(str, max) {
  if (str.length <= max) return str;
  return str.substring(0, max - 1) + '.';
}

const baseFont = {
  fontFamily: "'Arial', 'Helvetica', 'Segoe UI', sans-serif",
  fontSize: '12px',
  lineHeight: '1.1',
  fontWeight: 400,
  color: '#000',
  WebkitFontSmoothing: 'none',
  MozOsxFontSmoothing: 'unset',
};

const rowStyle = {
  display: 'flex',
  justifyContent: 'space-between',
  alignItems: 'baseline',
  margin: 0,
  padding: 0,
};

const hrSolid = {
  border: 'none',
  borderBottom: '2px solid #000',
  margin: '2px 0',
  padding: 0,
};

const hrDashed = {
  border: 'none',
  borderBottom: '1px dashed #000',
  margin: '2px 0',
  padding: 0,
};

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
        ...baseFont,
      }}
    >
      <div
        className="ticket-inner-screen"
        style={{
          boxSizing: 'border-box',
          border: '2px dashed #cbd5e1',
          borderRadius: '8px',
          padding: '4px 6px',
          width: '100%',
          overflow: 'hidden',
        }}
      >
        {/* Header */}
        <div style={{ textAlign: 'center', marginBottom: '1px' }}>
          <div style={{ fontSize: '12px', fontWeight: 500, lineHeight: '1.1', wordWrap: 'break-word', whiteSpace: 'normal' }}>
            {ticketConfig.business_name}
          </div>
          {ticketConfig.rfc && (
            <div style={{ fontSize: '11px', lineHeight: '1.2' }}>RFC: {ticketConfig.rfc}</div>
          )}
          {ticketConfig.address && (
            <div style={{ fontSize: '10px', lineHeight: '1.2', wordWrap: 'break-word' }}>{ticketConfig.address}</div>
          )}
          {ticketConfig.phone && (
            <div style={{ fontSize: '11px', lineHeight: '1.2' }}>Tel: {ticketConfig.phone}</div>
          )}
          {ticketConfig.header_message && (
            <div style={{ fontSize: '10px', fontStyle: 'italic', lineHeight: '1.2', marginTop: '1px', wordWrap: 'break-word' }}>
              {ticketConfig.header_message}
            </div>
          )}
        </div>

        <hr style={hrSolid} />

        {/* Order info */}
        <div style={{ margin: '2px 0' }}>
          {orderId && (
            <div style={rowStyle}>
              <span>Folio:</span>
              <span style={{ fontWeight: 700 }}>{orderId.substring(0, 8).toUpperCase()}</span>
            </div>
          )}
          <div style={rowStyle}>
            <span>Fecha:</span>
            <span>{dateStr}</span>
          </div>
          <div style={rowStyle}>
            <span>Pago:</span>
            <span style={{ fontWeight: 700 }}>{paymentLabel}</span>
          </div>
        </div>

        <hr style={hrDashed} />

        {/* Column headers */}
        <pre style={{ ...preStyle, fontWeight: 500 }}>
          {padLine('PRODUCTO', 'IMPORTE')}
        </pre>

        <hr style={hrDashed} />

        {/* Items */}
        <div style={{ margin: '2px 0' }}>
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
                <pre style={{ ...preStyle, fontSize: '10px', color: '#000' }}>
                  {'  ' + qty + ' x ' + formatMoney(basePrice)}
                  {discount > 0 ? '  -' + formatMoney(discount) : ''}
                </pre>
                {(item.promotion || item.promotion_name) && (
                  <pre style={{ ...preStyle, fontSize: '10px', fontStyle: 'italic', color: '#000' }}>
                    {'  ' + truncate(item.promotion?.name || item.promotion_name, LINE_WIDTH - 2)}
                  </pre>
                )}
              </div>
            );
          })}
        </div>

        <hr style={hrDashed} />

        {/* Totals */}
        <div style={{ margin: '1px 0' }}>
          <pre style={preStyle}>
            {padLine('Subtotal:', formatMoney(subtotal))}
          </pre>
          <pre style={preStyle}>
            {padLine(`IVA (${(taxRate * 100).toFixed(0)}%):`, formatMoney(ivaTotal))}
          </pre>
          <pre style={{ ...preStyle, fontWeight: 500 }}>
            {padLine('TOTAL:', formatMoney(total))}
          </pre>
          {showCashChange && (
            <>
              <pre style={preStyle}>{SEP_SINGLE}</pre>
              <pre style={preStyle}>
                {padLine('Recibido:', formatMoney(amountReceived))}
              </pre>
              <pre style={{ ...preStyle, fontWeight: 500 }}>
                {padLine('Cambio:', formatMoney(amountChange))}
              </pre>
            </>
          )}
        </div>

        {/* Custom legend */}
        {legend && (
          <>
            <hr style={hrDashed} />
            <div style={{ textAlign: 'center', fontSize: '10px', lineHeight: '1.2', margin: '2px 0', wordWrap: 'break-word', whiteSpace: 'pre-wrap' }}>
              {legend}
            </div>
          </>
        )}

        <hr style={hrSolid} />

        {/* Footer */}
        <div style={{ textAlign: 'center', fontSize: '10px', lineHeight: '1.2', marginTop: '2px' }}>
          {ticketConfig.footer_message && (
            <div style={{ fontStyle: 'italic', marginBottom: '1px', wordWrap: 'break-word' }}>
              {ticketConfig.footer_message}
            </div>
          )}
          <div style={{ color: '#000' }}>v{ticketConfig.version} - Cronos POS</div>
        </div>
      </div>
    </div>
  );
});

export default TicketPreview;
