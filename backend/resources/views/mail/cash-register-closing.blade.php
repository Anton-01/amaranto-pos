<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="format-detection" content="telephone=no">
<title>Reporte de Cierres de Caja</title>
<style>
  body { margin: 0; padding: 0; background: #f1f5f9; font-family: Arial, sans-serif; font-size: 14px; color: #1e293b; -webkit-text-size-adjust: 100%; }
  .wrapper { max-width: 680px; margin: 0 auto; padding: 24px 16px; }
  .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,.06); }
  .card-header { background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); padding: 28px 32px; }
  .card-header h1 { color: #fff; font-size: 20px; margin: 0 0 4px 0; }
  .card-header p { color: #c7d2fe; font-size: 12px; margin: 0; }
  .card-body { padding: 28px 32px; }
  .summary-grid { display: table; width: 100%; margin-bottom: 24px; }
  .summary-cell { display: table-cell; width: 33.3%; padding: 12px; background: #f8fafc; border-radius: 8px; text-align: center; }
  .summary-cell + .summary-cell { margin-left: 8px; }
  .summary-value { font-size: 22px; font-weight: 700; color: #4f46e5; }
  .summary-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-top: 2px; }
  .section-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; color: #6366f1; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin: 20px 0 12px 0; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th { background: #4f46e5; color: #fff; padding: 8px 10px; text-align: left; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
  td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
  .num { text-align: right; white-space: nowrap; }
  th.num { text-align: right; }
  .positive { color: #16a34a; font-weight: 700; }
  .negative { color: #dc2626; font-weight: 700; }
  .zero { color: #64748b; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }
  .badge-deficit { background: #fee2e2; color: #dc2626; }
  .badge-surplus { background: #dcfce7; color: #16a34a; }
  .badge-exact   { background: #dbeafe; color: #1d4ed8; }
  .footer { margin-top: 20px; text-align: center; font-size: 11px; color: #94a3b8; }
  .filters-bar { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 12px; color: #1d4ed8; }
  .immutable-note { font-size: 10px; color: #94a3b8; margin-top: 16px; padding-top: 12px; border-top: 1px solid #e2e8f0; }

  /*
   * Closings table. The zebra striping is emitted per row from Blade instead
   * of with :nth-child because each closing now occupies TWO rows — its
   * summary and its payment breakdown — and because Outlook's Word engine
   * does not implement :nth-child at all.
   */
  .closing-row td { border-bottom: 0; padding-top: 12px; }
  .closing-row.alt td { background: #f8fafc; }
  .closing-row td.cell-date { font-weight: 600; }

  /* Payment breakdown nested under the closing it belongs to. */
  .breakdown-row td { padding: 0 10px 14px 10px; border-bottom: 1px solid #e2e8f0; }
  .breakdown-row.alt td { background: #f8fafc; }
  .breakdown-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
  .breakdown-title { font-size: 9px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; color: #6366f1; padding: 8px 10px 6px 10px; }
  table.breakdown { font-size: 11px; }
  table.breakdown th { background: #eef2ff; color: #4338ca; padding: 6px 10px; font-size: 9px; }
  table.breakdown td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; }
  table.breakdown tr.alt td { background: #f8fafc; }
  table.breakdown td.bd-name { font-weight: 600; color: #334155; }
  table.breakdown tr.bd-subtotal td { background: #eef2ff; font-weight: 700; color: #4338ca; border-bottom: 0; }
  .bd-empty { padding: 10px; font-size: 11px; color: #94a3b8; }
  .bd-note { padding: 8px 10px; font-size: 10px; color: #92400e; background: #fffbeb; border-top: 1px solid #fde68a; }

  /* Mobile labels: printed in the markup, hidden inline so a client that
     drops the <style> block never shows them, revealed by the media query. */
  .lbl { color: #64748b; font-weight: 600; }

  /*
   * Below 520px the two tables stop being tables: every cell becomes a line
   * of its own, prefixed by the label its column header used to carry. Rows
   * of four money columns are unreadable on a 360px phone, and horizontal
   * scrolling inside an email client is worse than the stacking.
   *
   * Clients that ignore media queries (Outlook desktop, some webmail) keep
   * the tabular layout, which is the correct rendering for their viewport.
   */
  @media only screen and (max-width: 520px) {
    .wrapper { padding: 12px 8px !important; }
    .card-header { padding: 20px 16px !important; }
    .card-body { padding: 18px 14px !important; }

    .summary-grid, .summary-cell { display: block !important; width: auto !important; }
    .summary-cell { margin-bottom: 8px !important; }

    .closings thead, .breakdown thead { display: none !important; }

    /*
     * The tables themselves become blocks too, not just their rows: a <table>
     * whose cells are all display:block still runs the table layout algorithm
     * and sizes itself to its widest content, so the stacking would not hold
     * the narrow viewport on its own.
     */
    .closings, .closings tbody, .closings tr, .closings td,
    table.breakdown, table.breakdown tbody, table.breakdown tr, table.breakdown td {
      display: block !important;
      /*
       * width:auto, never 100%. These elements carry horizontal padding and
       * the default box model adds it outside the declared width, so a nested
       * 100% would push each level 24px past its parent — and .card clips
       * with overflow:hidden, so the excess is cut rather than scrolled.
       * box-sizing is set as well for the clients that ignore width:auto.
       */
      width: auto !important;
      max-width: 100% !important;
      box-sizing: border-box !important;
    }

    .closings td, table.breakdown td {
      text-align: left !important;
      white-space: normal !important;
      overflow-wrap: break-word !important;
      padding: 3px 12px !important;
      border-bottom: 0 !important;
    }

    .lbl { display: inline-block !important; min-width: 92px; }

    .closing-row td.cell-date {
      font-size: 14px !important;
      font-weight: 700 !important;
      padding: 14px 12px 6px 12px !important;
      border-top: 2px solid #e2e8f0 !important;
    }
    .closing-row td.cell-status { padding-top: 8px !important; padding-bottom: 10px !important; }

    .breakdown-row td { padding: 0 0 16px 0 !important; }
    .breakdown-title { padding: 10px 12px 4px 12px !important; }
    table.breakdown td.bd-name {
      font-size: 13px !important;
      padding-top: 10px !important;
      border-top: 1px solid #e2e8f0 !important;
    }
    /* Only the label line opens the subtotal block; padding every cell of it
       would stretch the group to twice the height of a payment method. */
    table.breakdown tr.bd-subtotal td.bd-name {
      border-top: 2px solid #c7d2fe !important;
      padding-top: 8px !important;
    }
    table.breakdown tr.bd-subtotal td:last-child { padding-bottom: 8px !important; }
    .bd-note { padding: 8px 12px !important; }
  }
</style>
</head>
<body>
@php
    $money = fn ($value) => '$'.number_format((float) $value, 2);

    /**
     * Signed amount with the sign carried by the glyph, so the reader never
     * has to work out whether a difference is a shortfall or a surplus.
     */
    $signed = fn ($value) => ((float) $value < 0 ? '−' : ((float) $value > 0 ? '+' : ''))
        .'$'.number_format(abs((float) $value), 2);

    $toneClass = fn ($value) => (float) $value < 0 ? 'negative' : ((float) $value > 0 ? 'positive' : 'zero');
@endphp
<div class="wrapper">
  <div class="card">
    <div class="card-header">
      <h1>Reporte de Cierres de Caja</h1>
      <p>Cronos POS &mdash; Generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }}</p>
    </div>
    <div class="card-body">

      @if (!empty($filters['date_from']) || !empty($filters['date_to']))
      <div class="filters-bar">
        📅 Periodo filtrado:
        {{ !empty($filters['date_from']) ? $filters['date_from'] : 'inicio' }}
        al
        {{ !empty($filters['date_to']) ? $filters['date_to'] : 'hoy' }}
      </div>
      @endif

      <!-- Summary stats -->
      <div class="summary-grid">
        <div class="summary-cell">
          <div class="summary-value">{{ $total }}</div>
          <div class="summary-label">Total Cierres</div>
        </div>
        <div class="summary-cell">
          @php $totalDef = $closings->where('difference_amount', '<', 0)->count(); @endphp
          <div class="summary-value" style="color:#dc2626">{{ $totalDef }}</div>
          <div class="summary-label">Con Faltante</div>
        </div>
        <div class="summary-cell">
          @php $totalSur = $closings->where('difference_amount', '>', 0)->count(); @endphp
          <div class="summary-value" style="color:#16a34a">{{ $totalSur }}</div>
          <div class="summary-label">Con Sobrante</div>
        </div>
      </div>

      <!-- Closings table, each one followed by its payment breakdown -->
      <div class="section-title">Detalle de Cierres</div>
      @if ($closings->isEmpty())
        <p style="color:#64748b;font-size:13px;">No hay cierres registrados para el periodo seleccionado.</p>
      @else
      <table class="closings">
        <thead>
          <tr>
            <th>Fecha/Hora</th>
            <th>Operador</th>
            <th class="num">Esperado</th>
            <th class="num">Declarado</th>
            <th class="num">Diferencia</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($closings as $closing)
            @php
              $diff = (float) $closing->difference_amount;
              $badgeClass = $diff < 0 ? 'badge-deficit' : ($diff > 0 ? 'badge-surplus' : 'badge-exact');
              $badgeLabel = $diff < 0 ? 'FALTANTE' : ($diff > 0 ? 'SOBRANTE' : 'EXACTO');
              $stripe = $loop->iteration % 2 === 0 ? ' alt' : '';

              $breakdown = collect($closing->payment_breakdown ?? []);

              /*
               * The breakdown covers sales only: expected_amount also carries
               * the opening float and subtracts the petty cash of the shift
               * (Fondo + Ventas − Caja Chica). Publishing the subtotal next to
               * the closing total is what keeps the difference from reading as
               * an arithmetic error in a document meant for auditing.
               */
              $breakdownExpected = round((float) $breakdown->sum('expected'), 2);
              $breakdownDeclared = round((float) $breakdown->sum('declared'), 2);
              $unallocated = round((float) $closing->expected_amount - $breakdownExpected, 2);
            @endphp
            <tr class="closing-row{{ $stripe }}">
              <td class="cell-date">{{ $closing->created_at->format('d/m/Y H:i') }}</td>
              <td class="cell-user"><span class="lbl" style="display:none">Operador: </span>{{ $closing->closedByUser?->name ?? '—' }}</td>
              <td class="num"><span class="lbl" style="display:none">Esperado: </span>{{ $money($closing->expected_amount) }}</td>
              <td class="num"><span class="lbl" style="display:none">Declarado: </span>{{ $money($closing->declared_amount) }}</td>
              <td class="num {{ $toneClass($diff) }}"><span class="lbl" style="display:none">Diferencia: </span>{{ $signed($diff) }}</td>
              <td class="cell-status"><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
            </tr>
            <tr class="breakdown-row{{ $stripe }}">
              <td colspan="6">
                <div class="breakdown-panel">
                  <div class="breakdown-title">Desglose por Método de Pago</div>
                  @if ($breakdown->isEmpty())
                    <div class="bd-empty">Este cierre no registró desglose por método de pago.</div>
                  @else
                  <table class="breakdown">
                    <thead>
                      <tr>
                        <th>Método de Pago</th>
                        <th class="num">Esperado</th>
                        <th class="num">Declarado</th>
                        <th class="num">Diferencia</th>
                      </tr>
                    </thead>
                    <tbody>
                      @foreach ($breakdown as $method)
                        @php $methodDiff = (float) ($method['difference'] ?? 0); @endphp
                        <tr class="{{ $loop->iteration % 2 === 0 ? 'alt' : '' }}">
                          <td class="bd-name">{{ $method['name'] ?? '—' }}</td>
                          <td class="num"><span class="lbl" style="display:none">Esperado: </span>{{ $money($method['expected'] ?? 0) }}</td>
                          <td class="num"><span class="lbl" style="display:none">Declarado: </span>{{ $money($method['declared'] ?? 0) }}</td>
                          <td class="num {{ $toneClass($methodDiff) }}"><span class="lbl" style="display:none">Diferencia: </span>{{ $signed($methodDiff) }}</td>
                        </tr>
                      @endforeach
                      <tr class="bd-subtotal">
                        <td class="bd-name">Subtotal ventas</td>
                        <td class="num"><span class="lbl" style="display:none">Esperado: </span>{{ $money($breakdownExpected) }}</td>
                        <td class="num"><span class="lbl" style="display:none">Declarado: </span>{{ $money($breakdownDeclared) }}</td>
                        <td class="num {{ $toneClass($breakdownDeclared - $breakdownExpected) }}"><span class="lbl" style="display:none">Diferencia: </span>{{ $signed($breakdownDeclared - $breakdownExpected) }}</td>
                      </tr>
                    </tbody>
                  </table>
                  @if (abs($unallocated) >= 0.01)
                    <div class="bd-note">
                      Diferencia de {{ $signed($unallocated) }} entre el subtotal de ventas y el esperado del cierre:
                      corresponde al fondo de apertura y a la caja chica del turno, que no se desglosan por método de pago.
                    </div>
                  @endif
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      @endif

      <div class="immutable-note">
        Los registros de cierre de caja en Cronos POS son inmutables y forenses. No pueden modificarse ni eliminarse una vez generados.
        Este correo fue enviado automáticamente desde el sistema. Para consultas, contacta al administrador.
      </div>
    </div>
  </div>
  <div class="footer">
    Cronos POS &bull; Sistema de Punto de Venta &bull; {{ now()->year }}
  </div>
</div>
</body>
</html>
