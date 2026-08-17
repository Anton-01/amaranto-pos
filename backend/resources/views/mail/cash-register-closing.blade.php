<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,date=no,address=no,email=no">
<title>Reporte de Cierres de Caja</title>
<style>
  /* Base styles. Layout relies on HTML tables and percentage widths only:
     flexbox and CSS grid are dropped by Outlook and by the Gmail mobile app. */
  body {
    margin: 0;
    padding: 0;
    background: #f1f5f9;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    color: #1e293b;
    /* Stops iOS Mail and Gmail iOS from inflating font sizes on their own. */
    -webkit-text-size-adjust: 100%;
    -ms-text-size-adjust: 100%;
  }
  table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
  .wrapper { max-width: 680px; margin: 0 auto; padding: 24px 16px; }
  .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,.06); }
  /* Solid colour first so clients without gradient support keep white text readable. */
  .card-header { background-color: #4f46e5; background-image: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); padding: 28px 32px; }
  .card-header h1 { color: #fff; font-size: 20px; line-height: 1.3; margin: 0 0 4px 0; }
  .card-header p { color: #c7d2fe; font-size: 12px; line-height: 1.4; margin: 0; }
  .card-body { padding: 28px 32px; }

  /* Summary metrics: a real table on desktop, three stacked blocks on mobile. */
  .summary-table { width: 100%; margin-bottom: 24px; }
  .summary-cell { width: 32%; padding: 14px 10px; background: #f8fafc; border-radius: 8px; text-align: center; vertical-align: top; }
  .summary-gutter { width: 2%; font-size: 0; line-height: 0; }
  .summary-value { font-size: 22px; line-height: 1.2; font-weight: 700; color: #4f46e5; }
  .summary-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-top: 4px; }

  .section-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; color: #6366f1; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin: 20px 0 12px 0; }

  /* Horizontal scroll fallback for clients that strip <style> and therefore
     never reach the media query below (e.g. Gmail app with a non-Google account). */
  .table-scroll { width: 100%; max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }

  .detail-table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .detail-table th { background: #4f46e5; color: #fff; padding: 10px; text-align: left; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
  .detail-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; word-break: break-word; overflow-wrap: break-word; }
  .detail-table tbody tr:nth-child(even) td { background: #f8fafc; }
  /* Column label printed inside each cell; only shown once the rows stack. */
  .stack-label { display: none; }

  .positive { color: #16a34a; font-weight: 700; }
  .negative { color: #dc2626; font-weight: 700; }
  .zero { color: #64748b; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }
  .badge-deficit { background: #fee2e2; color: #dc2626; }
  .badge-surplus { background: #dcfce7; color: #16a34a; }
  .badge-exact   { background: #dbeafe; color: #1d4ed8; }
  .footer { margin-top: 20px; text-align: center; font-size: 11px; color: #94a3b8; }
  .filters-bar { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 12px; line-height: 1.5; color: #1d4ed8; }
  .immutable-note { font-size: 10px; line-height: 1.6; color: #94a3b8; margin-top: 16px; padding-top: 12px; border-top: 1px solid #e2e8f0; }

  @media only screen and (max-width: 600px) {
    .wrapper { padding: 12px 8px !important; }
    .card-header { padding: 20px 18px !important; }
    .card-header h1 { font-size: 18px !important; }
    .card-body { padding: 20px 16px !important; }

    /* Metric cards take the full width and stack instead of being squeezed
       into three narrow columns. */
    .summary-table,
    .summary-table tbody,
    .summary-table tr,
    .summary-cell {
      display: block !important;
      width: 100% !important;
      box-sizing: border-box !important;
    }
    .summary-cell {
      padding: 16px !important;
      margin-bottom: 10px !important;
      text-align: center !important;
    }
    .summary-gutter { display: none !important; }
    .summary-value { font-size: 26px !important; }
    .summary-label { font-size: 11px !important; }

    /* Detail table turns into one stacked card per closing: the header row is
       hidden and every cell carries its own label. No horizontal overflow left. */
    .table-scroll { overflow-x: visible !important; }
    .detail-head { display: none !important; }
    .detail-table,
    .detail-table tbody,
    .detail-row,
    .detail-cell {
      display: block !important;
      width: 100% !important;
      box-sizing: border-box !important;
    }
    .detail-row {
      margin-bottom: 12px !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 8px !important;
      overflow: hidden !important;
    }
    .detail-table tbody tr:nth-child(even) td,
    .detail-cell {
      background: #ffffff !important;
    }
    .detail-cell {
      padding: 10px 14px !important;
      text-align: right !important;
      font-size: 13px !important;
      border-bottom: 1px solid #f1f5f9 !important;
    }
    .detail-cell-primary,
    .detail-table tbody tr:nth-child(even) td.detail-cell-primary {
      background: #f8fafc !important;
      text-align: left !important;
      font-weight: 700 !important;
    }
    .detail-cell-last { border-bottom: 0 !important; }
    .stack-label {
      display: inline-block !important;
      float: left !important;
      margin-right: 12px !important;
      font-size: 10px !important;
      font-weight: 700 !important;
      text-transform: uppercase !important;
      letter-spacing: 0.5px !important;
      color: #64748b !important;
    }
    .detail-cell-primary .stack-label { display: none !important; }
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
      @php
        $totalDef = $closings->where('difference_amount', '<', 0)->count();
        $totalSur = $closings->where('difference_amount', '>', 0)->count();
      @endphp
      <table class="summary-table" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td class="summary-cell" width="32%">
            <div class="summary-value">{{ $total }}</div>
            <div class="summary-label">Total Cierres</div>
          </td>
          <td class="summary-gutter" width="2%">&nbsp;</td>
          <td class="summary-cell" width="32%">
            <div class="summary-value" style="color:#dc2626">{{ $totalDef }}</div>
            <div class="summary-label">Con Faltante</div>
          </td>
          <td class="summary-gutter" width="2%">&nbsp;</td>
          <td class="summary-cell" width="32%">
            <div class="summary-value" style="color:#16a34a">{{ $totalSur }}</div>
            <div class="summary-label">Con Sobrante</div>
          </td>
        </tr>
      </table>

      <!-- Closings table, each one followed by its payment breakdown -->
      <div class="section-title">Detalle de Cierres</div>
      @if ($closings->isEmpty())
        <p style="color:#64748b;font-size:13px;">No hay cierres registrados para el periodo seleccionado.</p>
      @else
      <div class="table-scroll">
        <table class="detail-table" width="100%" cellpadding="0" cellspacing="0" border="0">
          <thead class="detail-head">
            <tr>
              <th>Fecha/Hora</th>
              <th>Operador</th>
              <th>Esperado</th>
              <th>Declarado</th>
              <th>Diferencia</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($closings as $closing)
              @php
                $diff = (float) $closing->difference_amount;
                $diffClass = $diff < 0 ? 'negative' : ($diff > 0 ? 'positive' : '');
                $badgeClass = $diff < 0 ? 'badge-deficit' : ($diff > 0 ? 'badge-surplus' : 'badge-exact');
                $badgeLabel = $diff < 0 ? 'FALTANTE' : ($diff > 0 ? 'SOBRANTE' : 'EXACTO');
                $sign = $diff < 0 ? '−' : ($diff > 0 ? '+' : '');
              @endphp
              <tr class="detail-row">
                <td class="detail-cell detail-cell-primary">
                  <span class="stack-label">Fecha/Hora</span>{{ $closing->created_at->format('d/m/Y H:i') }}
                </td>
                <td class="detail-cell">
                  <span class="stack-label">Operador</span>{{ $closing->closedByUser?->name ?? '—' }}
                </td>
                <td class="detail-cell">
                  <span class="stack-label">Esperado</span>${{ number_format((float)$closing->expected_amount, 2) }}
                </td>
                <td class="detail-cell">
                  <span class="stack-label">Declarado</span>${{ number_format((float)$closing->declared_amount, 2) }}
                </td>
                <td class="detail-cell {{ $diffClass }}">
                  <span class="stack-label">Diferencia</span>{{ $sign }}${{ number_format(abs($diff), 2) }}
                </td>
                <td class="detail-cell detail-cell-last">
                  <span class="stack-label">Estado</span><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
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
