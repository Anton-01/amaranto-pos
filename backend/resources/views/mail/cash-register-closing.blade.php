<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte de Cierres de Caja</title>
<style>
  body { margin: 0; padding: 0; background: #f1f5f9; font-family: Arial, sans-serif; font-size: 14px; color: #1e293b; }
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
  tr:nth-child(even) td { background: #f8fafc; }
  .positive { color: #16a34a; font-weight: 700; }
  .negative { color: #dc2626; font-weight: 700; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }
  .badge-deficit { background: #fee2e2; color: #dc2626; }
  .badge-surplus { background: #dcfce7; color: #16a34a; }
  .badge-exact   { background: #dbeafe; color: #1d4ed8; }
  .footer { margin-top: 20px; text-align: center; font-size: 11px; color: #94a3b8; }
  .filters-bar { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 12px; color: #1d4ed8; }
  .immutable-note { font-size: 10px; color: #94a3b8; margin-top: 16px; padding-top: 12px; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
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

      <!-- Closings table -->
      <div class="section-title">Detalle de Cierres</div>
      @if ($closings->isEmpty())
        <p style="color:#64748b;font-size:13px;">No hay cierres registrados para el periodo seleccionado.</p>
      @else
      <table>
        <thead>
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
            <tr>
              <td>{{ $closing->created_at->format('d/m/Y H:i') }}</td>
              <td>{{ $closing->closedByUser?->name ?? '—' }}</td>
              <td>${{ number_format((float)$closing->expected_amount, 2) }}</td>
              <td>${{ number_format((float)$closing->declared_amount, 2) }}</td>
              <td class="{{ $diffClass }}">{{ $sign }}${{ number_format(abs($diff), 2) }}</td>
              <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
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
