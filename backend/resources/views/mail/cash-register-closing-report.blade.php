@extends('mail.layouts.corporate')

@section('title', 'Reporte de Cierre de Caja - Cronos POS')

@section('content')
<h2 style="margin:0 0 16px 0;font-size:20px;font-weight:700;color:#0f172a;">
  Reporte de Cierre de Caja
</h2>

<p style="margin:0 0 20px 0;font-size:14px;color:#475569;line-height:1.6;">
  Se adjunta el reporte del arqueo de caja correspondiente al cierre registrado en el sistema.
  A continuacion se presenta el resumen ejecutivo de las cifras del periodo.
</p>

{{-- Metrics --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
  <tr>
    <td style="padding:20px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Operador</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;font-weight:600;">{{ $operatorName }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Fecha del Cierre</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;">{{ $closingDate }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Monto Esperado</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;font-weight:600;">${{ number_format($expectedAmount, 2) }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Monto Declarado</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;font-weight:600;">${{ number_format($declaredAmount, 2) }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Diferencia Contable</span>
          </td>
          <td style="padding:8px 0;text-align:right;">
            @php
              $diffColor = $differenceAmount < 0 ? '#dc2626' : ($differenceAmount > 0 ? '#16a34a' : '#0f172a');
              $diffSign = $differenceAmount < 0 ? '-' : ($differenceAmount > 0 ? '+' : '');
              $diffLabel = $differenceAmount < 0 ? 'FALTANTE' : ($differenceAmount > 0 ? 'SOBRANTE' : 'EXACTO');
            @endphp
            <span style="font-size:16px;font-weight:700;color:{{ $diffColor }};">
              {{ $diffSign }}${{ number_format(abs($differenceAmount), 2) }}
            </span>
            <br>
            <span style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;
              {{ $differenceAmount < 0 ? 'background-color:#fee2e2;color:#dc2626;' : ($differenceAmount > 0 ? 'background-color:#dcfce7;color:#16a34a;' : 'background-color:#dbeafe;color:#1d4ed8;') }}">
              {{ $diffLabel }}
            </span>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

@if(!empty($paymentBreakdown))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;">
  <tr>
    <td>
      <p style="margin:0 0 10px 0;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">
        Desglose por Metodo de Pago
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        @foreach($paymentBreakdown as $method => $total)
        <tr>
          <td style="padding:6px 0;font-size:13px;color:#475569;">{{ $method }}</td>
          <td style="padding:6px 0;font-size:13px;color:#0f172a;font-weight:600;text-align:right;">${{ number_format($total, 2) }}</td>
        </tr>
        @endforeach
      </table>
    </td>
  </tr>
</table>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background-color:#f0f9ff;border-radius:8px;border:1px solid #bae6fd;">
  <tr>
    <td style="padding:14px 16px;">
      <p style="margin:0;font-size:12px;color:#0369a1;line-height:1.5;">
        El archivo PDF con el detalle completo del arqueo se encuentra adjunto a este correo
        para su resguardo contable y administrativo.
      </p>
    </td>
  </tr>
</table>

<p style="margin:20px 0 0 0;font-size:12px;color:#94a3b8;line-height:1.5;">
  Los registros de cierre de caja son inmutables y no pueden modificarse una vez generados.
</p>
@endsection
