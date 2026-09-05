import { forwardRef } from 'react';

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

/**
 * Typography of the on-screen variant.
 *
 * No `fontFamily` on purpose: the ticket inherits the same sans-serif stack as
 * the rest of the system, so a preview embedded in a dialog reads as part of
 * that dialog instead of as a pasted-in terminal dump.
 */
const screenFont = {
  fontSize: '12px',
  lineHeight: '1.45',
  fontWeight: 400,
  color: '#1e293b',
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

const hrScreenSolid = {
  border: 'none',
  borderBottom: '1px solid #cbd5e1',
  margin: '8px 0',
  padding: 0,
};

const hrScreenDashed = {
  border: 'none',
  borderBottom: '1px dashed #cbd5e1',
  margin: '8px 0',
  padding: 0,
};

const LINE_WIDTH = 32;
const maxNameLen = LINE_WIDTH - 10;
const SEP_SINGLE = '-'.repeat(LINE_WIDTH);

const preStyle = {
  fontFamily: "'Courier New', Courier, monospace",
  fontSize: '12px',
  lineHeight: '1.1',
  fontWeight: 400,
  color: '#000',
  margin: 0,
  padding: 0,
  whiteSpace: 'pre',
  overflow: 'hidden',
};

function padLine(left, right) {
  const rightLen = right.length;
  const maxLeft = LINE_WIDTH - rightLen - 1;
  if (left.length > maxLeft) {
    left = left.substring(0, maxLeft - 2) + '..';
  }
  const spaces = LINE_WIDTH - left.length - rightLen;
  return left + ' '.repeat(Math.max(spaces, 1)) + right;
}

function formatMoney(val) {
  return '$' + parseFloat(val).toFixed(2);
}

/**
 * One "label ....... amount" line of the ticket.
 *
 * The thermal variant pads the gap with spaces inside a monospace `<pre>`,
 * which is the only way to line the amounts up in a 32-column font. The screen
 * variant has no fixed column count, so it lets flexbox do the same job with
 * the system font.
 */
function TicketLine({ mono, left, right, bold = false, style }) {
  if (mono) {
    return (
      <pre style={{ ...preStyle, ...(bold ? { fontWeight: 500 } : null), ...style }}>
        {padLine(left, right)}
      </pre>
    );
  }
  return (
    <div style={{ ...rowStyle, gap: '12px', ...(bold ? { fontWeight: 600 } : null), ...style }}>
      <span style={{ minWidth: 0, wordBreak: 'break-word' }}>{left}</span>
      <span style={{ whiteSpace: 'nowrap' }}>{right}</span>
    </div>
  );
}

/**
 * Ticket rendering shared by the checkout preview, the sales history reprint
 * and the ticket configuration screen.
 *
 * `variant` decides which of the two readings it produces:
 *
 * - `print` (default) reproduces the 58mm thermal output character by
 *   character — monospace columns padded to 32 chars — because that view is
 *   what `window.print()` puts on paper and what a cashier compares against a
 *   printed ticket.
 * - `screen` is a preview embedded in a dialog. It carries no font of its own
 *   and sits on a soft grey card, so it belongs to the interface around it
 *   rather than imitating the printer.
 */
const TicketPreview = forwardRef(function TicketPreview(
  { order, ticketConfig, customLegend, taxRate = 0.16, variant = 'print' },
  ref
) {
  if (!ticketConfig) return null;

  const isScreen = variant === 'screen';
  const mono = !isScreen;
  const hrMain = isScreen ? hrScreenSolid : hrSolid;
  const hrSoft = isScreen ? hrScreenDashed : hrDashed;
  const muted = isScreen ? '#64748b' : '#000';

  const items = order?.items || [];
  const subtotal = order?.subtotal ?? 0;
  const ivaTotal = order?.iva_total ?? 0;
  const total = order?.total ?? 0;
  const discountTotal = parseFloat(order?.discount_total ?? 0);
  const amountReceived = order?.amount_received ?? null;
  const amountChange = order?.amount_change ?? null;
  const paymentMethod = order?.payment_method;
  const paymentLabel = typeof paymentMethod === 'object'
    ? (paymentMethod?.name || 'N/A').toUpperCase()
    : (paymentMethod || 'N/A').toUpperCase();
  const legend = customLegend ?? order?.custom_legend ?? '';
  const orderId = order?.id || '';
  // Solo presentes en ventas de comedor; en mostrador quedan fuera del ticket.
  const tableName = order?.table_name_at_sale || order?.table?.name || '';
  const waiterName = order?.waiter_name_at_sale || order?.waiter?.name || '';
  const createdAt = order?.created_at ? new Date(order.created_at) : new Date();

  const dateStr = createdAt.toLocaleString('es-MX', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });

  const showCashChange = amountReceived != null && parseFloat(amountReceived) > 0;

  return (
    <div
      ref={ref}
      /* The print stylesheet isolates the page by this id; only the variant
         that can actually reach the printer claims it. */
      id={isScreen ? undefined : 'ticket-print-area'}
      className={isScreen ? 'rounded-md bg-slate-100 p-4' : undefined}
      style={{
        boxSizing: 'border-box',
        width: '100%',
        maxWidth: isScreen ? '300px' : '240px',
        margin: '0 auto',
        ...(isScreen ? screenFont : { padding: 0, backgroundColor: '#fff', ...baseFont }),
      }}
    >
      <div
        className={isScreen ? undefined : 'ticket-inner-screen'}
        style={{
          boxSizing: 'border-box',
          width: '100%',
          ...(isScreen ? null : {
            border: '2px dashed #cbd5e1',
            borderRadius: '8px',
            padding: '4px 6px',
            overflow: 'hidden',
          }),
        }}
      >
        {/* Header */}
        <div style={{ textAlign: 'center', marginBottom: '1px' }}>
          <div style={{ fontSize: isScreen ? '13px' : '12px', fontWeight: isScreen ? 600 : 500, lineHeight: isScreen ? '1.3' : '1.1', wordWrap: 'break-word', whiteSpace: 'normal' }}>
            {ticketConfig.business_name}
          </div>
          {ticketConfig.rfc && (
            <div style={{ fontSize: '11px', lineHeight: '1.2', color: muted }}>RFC: {ticketConfig.rfc}</div>
          )}
          {ticketConfig.address && (
            <div style={{ fontSize: '10px', lineHeight: '1.2', wordWrap: 'break-word', color: muted }}>{ticketConfig.address}</div>
          )}
          {ticketConfig.phone && (
            <div style={{ fontSize: '11px', lineHeight: '1.2', color: muted }}>Tel: {ticketConfig.phone}</div>
          )}
          {ticketConfig.header_message && (
            <div style={{ fontSize: '10px', fontStyle: 'italic', lineHeight: '1.2', marginTop: '1px', wordWrap: 'break-word', color: muted }}>
              {ticketConfig.header_message}
            </div>
          )}
        </div>

        <hr style={hrMain} />

        {/* Order info */}
        <div style={{ margin: '2px 0' }}>
          {orderId && (
            <div style={rowStyle}>
              <span style={{ color: muted }}>Folio:</span>
              <span style={{ fontWeight: 700 }}>{orderId.substring(0, 8).toUpperCase()}</span>
            </div>
          )}
          <div style={rowStyle}>
            <span style={{ color: muted }}>Fecha:</span>
            <span>{dateStr}</span>
          </div>
          <div style={rowStyle}>
            <span style={{ color: muted }}>Pago:</span>
            <span style={{ fontWeight: 700 }}>{paymentLabel}</span>
          </div>
          {tableName && (
            <div style={rowStyle}>
              <span style={{ color: muted }}>Mesa:</span>
              <span style={{ fontWeight: 700 }}>{tableName.toUpperCase()}</span>
            </div>
          )}
          {waiterName && (
            <div style={rowStyle}>
              <span style={{ color: muted }}>Atendio:</span>
              <span>{waiterName}</span>
            </div>
          )}
        </div>

        <hr style={hrSoft} />

        {/* Column headers */}
        <TicketLine
          mono={mono}
          left="PRODUCTO"
          right="IMPORTE"
          bold
          style={isScreen ? { fontSize: '10px', letterSpacing: '0.04em', color: muted } : null}
        />

        <hr style={hrSoft} />

        {/* Items */}
        <div style={{ margin: '2px 0' }}>
          {items.map((item, i) => {
            const productName = item.product?.name || item.product_name || 'Producto';
            const qty = item.quantity;
            const basePrice = parseFloat(item.base_price_at_sale ?? item.sale_price ?? 0);
            const discount = parseFloat(item.discount_amount_at_sale ?? item.discount ?? 0);
            const finalPrice = parseFloat(item.final_price_at_sale ?? (basePrice * qty - discount));

            return (
              <div key={i} style={{ marginBottom: isScreen ? '6px' : '1px' }}>
                <TicketLine
                  mono={mono}
                  left={isScreen ? productName : truncate(productName, maxNameLen)}
                  right={formatMoney(finalPrice)}
                  style={isScreen ? { fontWeight: 500 } : null}
                />
                {mono ? (
                  <pre style={{ ...preStyle, fontSize: '10px', color: '#000' }}>
                    {'  ' + qty + ' x ' + formatMoney(basePrice)}
                    {discount > 0 ? '  -' + formatMoney(discount) : ''}
                  </pre>
                ) : (
                  <div style={{ fontSize: '11px', color: muted }}>
                    {qty} x {formatMoney(basePrice)}
                    {discount > 0 ? '  -' + formatMoney(discount) : ''}
                  </div>
                )}
                {(item.promotion || item.promotion_name) && (
                  mono ? (
                    <pre style={{ ...preStyle, fontSize: '10px', fontStyle: 'italic', color: '#000' }}>
                      {'  ' + truncate(item.promotion?.name || item.promotion_name, LINE_WIDTH - 2)}
                    </pre>
                  ) : (
                    <div style={{ fontSize: '11px', fontStyle: 'italic', color: '#059669' }}>
                      {item.promotion?.name || item.promotion_name}
                    </div>
                  )
                )}
              </div>
            );
          })}
        </div>

        <hr style={hrSoft} />

        {/* Totals */}
        <div style={{ margin: '1px 0' }}>
          {discountTotal > 0 && (
            <TicketLine mono={mono} left="Descuento:" right={'-' + formatMoney(discountTotal)} bold />
          )}
          <TicketLine mono={mono} left="Subtotal:" right={formatMoney(subtotal)} />
          <TicketLine mono={mono} left={`IVA (${(taxRate * 100).toFixed(0)}%):`} right={formatMoney(ivaTotal)} />
          <TicketLine
            mono={mono}
            left="TOTAL:"
            right={formatMoney(total)}
            bold
            style={isScreen ? { fontSize: '14px', marginTop: '4px' } : null}
          />
          {showCashChange && (
            <>
              {mono ? <pre style={preStyle}>{SEP_SINGLE}</pre> : <hr style={hrScreenDashed} />}
              <TicketLine mono={mono} left="Recibido:" right={formatMoney(amountReceived)} />
              <TicketLine mono={mono} left="Cambio:" right={formatMoney(amountChange)} bold />
            </>
          )}
        </div>

        {/* Custom legend */}
        {legend && (
          <>
            <hr style={hrSoft} />
            <div style={{ textAlign: 'center', fontSize: '10px', lineHeight: '1.2', margin: '2px 0', wordWrap: 'break-word', whiteSpace: 'pre-wrap', color: muted }}>
              {legend}
            </div>
          </>
        )}

        <hr style={hrMain} />

        {/* Footer */}
        <div style={{ textAlign: 'center', fontSize: '10px', lineHeight: '1.2', marginTop: '2px', color: muted }}>
          {ticketConfig.footer_message && (
            <div style={{ fontStyle: 'italic', marginBottom: '1px', wordWrap: 'break-word' }}>
              {ticketConfig.footer_message}
            </div>
          )}
          <div>v{ticketConfig.version} - Cronos POS</div>
        </div>
      </div>
    </div>
  );
});

export default TicketPreview;
