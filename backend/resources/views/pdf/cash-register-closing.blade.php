<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Arqueo de Caja</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'DejaVu Sans', Arial, sans-serif;
    font-size: 12px;
    color: #1e293b;
    background: #fff;
    padding: 24px;
  }
  .header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 3px solid #4f46e5;
    padding-bottom: 16px;
    margin-bottom: 20px;
  }
  .brand-name {
    font-size: 22px;
    font-weight: 700;
    color: #4f46e5;
    letter-spacing: -0.5px;
  }
  .brand-subtitle {
    font-size: 10px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 2px;
  }
  .doc-info {
    text-align: right;
    font-size: 11px;
    color: #475569;
  }
  .doc-info .doc-title {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 4px;
  }
  .section {
    margin-bottom: 20px;
  }
  .section-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #6366f1;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 4px;
    margin-bottom: 10px;
  }
  .meta-grid {
    display: table;
    width: 100%;
  }
  .meta-row {
    display: table-row;
  }
  .meta-label {
    display: table-cell;
    width: 35%;
    color: #64748b;
    font-size: 11px;
    padding: 3px 8px 3px 0;
    font-weight: 600;
  }
  .meta-value {
    display: table-cell;
    color: #1e293b;
    font-size: 11px;
    padding: 3px 0;
  }
  table.breakdown {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
  }
  table.breakdown thead tr {
    background: #4f46e5;
    color: #fff;
  }
  table.breakdown thead th {
    padding: 8px 10px;
    text-align: left;
    font-weight: 600;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  table.breakdown thead th:last-child,
  table.breakdown thead th:nth-child(2),
  table.breakdown thead th:nth-child(3) {
    text-align: right;
  }
  table.breakdown tbody tr:nth-child(even) {
    background: #f8fafc;
  }
  table.breakdown tbody td {
    padding: 7px 10px;
    border-bottom: 1px solid #e2e8f0;
  }
  table.breakdown tbody td:last-child,
  table.breakdown tbody td:nth-child(2),
  table.breakdown tbody td:nth-child(3) {
    text-align: right;
    font-family: 'DejaVu Sans Mono', monospace;
  }
  .positive { color: #16a34a; font-weight: 700; }
  .negative { color: #dc2626; font-weight: 700; }
  .zero     { color: #64748b; }
  .totals-row td {
    background: #e0e7ff !important;
    font-weight: 700;
    border-top: 2px solid #4f46e5 !important;
    border-bottom: 2px solid #4f46e5 !important;
    font-size: 12px;
  }
  .summary-box {
    display: inline-block;
    padding: 12px 20px;
    border-radius: 8px;
    margin-top: 16px;
    width: 100%;
    text-align: center;
  }
  .summary-box.deficit {
    background: #fef2f2;
    border: 2px solid #fca5a5;
  }
  .summary-box.surplus {
    background: #f0fdf4;
    border: 2px solid #86efac;
  }
  .summary-box.exact {
    background: #f0f9ff;
    border: 2px solid #7dd3fc;
  }
  .summary-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
    margin-bottom: 4px;
  }
  .summary-amount {
    font-size: 28px;
    font-weight: 700;
  }
  .footer {
    margin-top: 30px;
    padding-top: 12px;
    border-top: 1px solid #e2e8f0;
    font-size: 9px;
    color: #94a3b8;
    text-align: center;
  }
  .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .badge-deficit  { background: #fee2e2; color: #dc2626; }
  .badge-surplus  { background: #dcfce7; color: #16a34a; }
  .badge-exact    { background: #dbeafe; color: #1d4ed8; }
  .immutable-notice {
    margin-top: 16px;
    padding: 8px 12px;
    background: #fffbeb;
    border-left: 3px solid #f59e0b;
    font-size: 10px;
    color: #92400e;
  }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div>
    <div class="brand-name">Cronos POS</div>
    <div class="brand-subtitle">Sistema de Punto de Venta</div>
  </div>
  <div class="doc-info">
    <div class="doc-title">ARQUEO DE CAJA</div>
    <div>Generado: {{ now()->format('d/m/Y H:i:s') }}</div>
    <div>Folio: {{ strtoupper(substr($closing->id, 0, 8)) }}</div>
  </div>
</div>

<!-- Meta info -->
<div class="section">
  <div class="section-title">Información del Cierre</div>
  <div class="meta-grid">
    <div class="meta-row">
      <div class="meta-label">Fecha y hora:</div>
      <div class="meta-value">{{ $closing->created_at->format('d/m/Y H:i:s') }}</div>
    </div>
    <div class="meta-row">
      <div class="meta-label">Operador:</div>
      <div class="meta-value">{{ $closing->closedByUser?->name ?? '—' }}</div>
    </div>
    <div class="meta-row">
      <div class="meta-label">Email operador:</div>
      <div class="meta-value">{{ $closing->closedByUser?->email ?? '—' }}</div>
    </div>
    <div class="meta-row">
      <div class="meta-label">ID Caja:</div>
      <div class="meta-value">{{ strtoupper(substr($closing->cash_register_id, 0, 8)) }}...</div>
    </div>
    <div class="meta-row">
      <div class="meta-label">Caja abierta por:</div>
      <div class="meta-value">{{ $closing->cashRegister?->user?->name ?? '—' }}</div>
    </div>
    <div class="meta-row">
      <div class="meta-label">Apertura de caja:</div>
      <div class="meta-value">{{ $closing->cashRegister?->opened_at ? $closing->cashRegister->opened_at->format('d/m/Y H:i:s') : '—' }}</div>
    </div>
  </div>
</div>

<!-- Payment breakdown -->
<div class="section">
  <div class="section-title">Desglose por Método de Pago</div>
  <table class="breakdown">
    <thead>
      <tr>
        <th>Método de Pago</th>
        <th>Esperado (Sistema)</th>
        <th>Declarado (Operador)</th>
        <th>Diferencia</th>
        <th>Estado</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($closing->payment_breakdown as $pm)
        @php
          $diff = (float) $pm['difference'];
          $diffClass = $diff < 0 ? 'negative' : ($diff > 0 ? 'positive' : 'zero');
          $badgeClass = $diff < 0 ? 'badge-deficit' : ($diff > 0 ? 'badge-surplus' : 'badge-exact');
          $badgeLabel = $diff < 0 ? 'FALTANTE' : ($diff > 0 ? 'SOBRANTE' : 'EXACTO');
          $fmt = fn($v) => '$' . number_format(abs($v), 2) . ' MXN';
        @endphp
        <tr>
          <td>{{ $pm['name'] }}</td>
          <td>{{ $fmt($pm['expected']) }}</td>
          <td>{{ $fmt($pm['declared']) }}</td>
          <td class="{{ $diffClass }}">
            {{ $diff < 0 ? '−' : ($diff > 0 ? '+' : '') }}{{ $fmt($diff) }}
          </td>
          <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
        </tr>
      @endforeach
      <!-- Totals -->
      <tr class="totals-row">
        <td>TOTALES</td>
        <td>${{ number_format((float)$closing->expected_amount, 2) }} MXN</td>
        <td>${{ number_format((float)$closing->declared_amount, 2) }} MXN</td>
        @php
          $totalDiff = (float) $closing->difference_amount;
          $totalDiffClass = $totalDiff < 0 ? 'negative' : ($totalDiff > 0 ? 'positive' : 'zero');
        @endphp
        <td class="{{ $totalDiffClass }}">
          {{ $totalDiff < 0 ? '−' : ($totalDiff > 0 ? '+' : '') }}${{ number_format(abs($totalDiff), 2) }} MXN
        </td>
        @php
          $badgeClass = $totalDiff < 0 ? 'badge-deficit' : ($totalDiff > 0 ? 'badge-surplus' : 'badge-exact');
          $badgeLabel = $totalDiff < 0 ? 'FALTANTE' : ($totalDiff > 0 ? 'SOBRANTE' : 'EXACTO');
        @endphp
        <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
      </tr>
    </tbody>
  </table>
</div>

<!-- Summary -->
<div class="section">
  @php
    $d = (float) $closing->difference_amount;
    $boxClass = $d < 0 ? 'deficit' : ($d > 0 ? 'surplus' : 'exact');
    $colorClass = $d < 0 ? 'negative' : ($d > 0 ? 'positive' : 'zero');
    $label = $d < 0 ? 'FALTANTE' : ($d > 0 ? 'SOBRANTE' : 'SIN DIFERENCIA');
  @endphp
  <div class="summary-box {{ $boxClass }}">
    <div class="summary-label">Resultado del Arqueo — {{ $label }}</div>
    <div class="summary-amount {{ $colorClass }}">
      {{ $d < 0 ? '−' : ($d > 0 ? '+' : '') }}${{ number_format(abs($d), 2) }} MXN
    </div>
  </div>
</div>

<!-- Immutability notice -->
<div class="immutable-notice">
  ⚠ Este documento es generado por el sistema Cronos POS y tiene carácter inmutable. El registro de cierre no puede ser modificado ni eliminado. Cualquier discrepancia debe reportarse al administrador del sistema.
</div>

<!-- Footer -->
<div class="footer">
  Cronos POS &bull; Arqueo de Caja &bull; {{ now()->format('Y') }} &bull; Documento generado automáticamente — No requiere firma.
</div>

</body>
</html>
