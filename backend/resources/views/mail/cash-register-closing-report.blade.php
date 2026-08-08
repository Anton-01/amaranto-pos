@extends('mail.layouts.corporate')

@section('title', 'Reporte de Cierre de Caja - Cronos POS')

@section('content')
{{-- Fiscal letterhead: makes the email itself the formal document, replacing
     the PDF attachment this report used to carry. --}}
@if(!empty($fiscal))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #4f46e5;padding-bottom:14px;margin-bottom:20px;">
  <tr>
    <td>
      <p style="margin:0 0 4px 0;font-size:16px;font-weight:700;color:#0f172a;letter-spacing:0.2px;">
        {{ $fiscal['business_name'] ?? 'Cronos POS' }}
      </p>
      @if(!empty($fiscal['rfc']))
      <p style="margin:0 0 2px 0;font-size:12px;color:#475569;">
        <span style="font-weight:600;">RFC:</span> <span style="font-family:'Courier New',monospace;">{{ $fiscal['rfc'] }}</span>
      </p>
      @endif
      @if(!empty($fiscal['address']))
      <p style="margin:0 0 2px 0;font-size:12px;color:#475569;">
        {{ $fiscal['address'] }}@if(!empty($fiscal['city'])), {{ $fiscal['city'] }}@endif
      </p>
      @endif
      @if(!empty($fiscal['phone']))
      <p style="margin:0;font-size:12px;color:#475569;">
        <span style="font-weight:600;">Tel:</span> {{ $fiscal['phone'] }}
      </p>
      @endif
    </td>
  </tr>
</table>
@endif

<h2 style="margin:0 0 6px 0;font-size:20px;font-weight:700;color:#0f172a;">
  Reporte de Cierre de Caja
</h2>
<p style="margin:0 0 20px 0;font-size:12px;color:#94a3b8;letter-spacing:0.5px;">
  FOLIO <span style="font-family:'Courier New',monospace;font-weight:700;color:#475569;">{{ $folio }}</span>
</p>

<p style="margin:0 0 20px 0;font-size:14px;color:#475569;line-height:1.6;">
  A continuacion se presenta el resumen del cierre de caja registrado en el sistema.
  Este documento es la constancia formal de la operacion del turno.
</p>

{{-- Executive summary --}}
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
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Modalidad</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;
              {{ $isAutomated ? 'background-color:#e0e7ff;color:#4338ca;' : 'background-color:#dcfce7;color:#16a34a;' }}">
              {{ $isAutomated ? 'CIERRE AUTOMATICO' : 'CIERRE MANUAL' }}
            </span>
          </td>
        </tr>
        <tr>
          <td style="padding:12px 0 0 0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Total Registrado</span>
          </td>
          <td style="padding:12px 0 0 0;text-align:right;">
            <span style="font-size:20px;font-weight:700;color:#0f172a;">${{ number_format($totalAmount, 2) }}</span>
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

@if($isAutomated)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background-color:#f0f9ff;border-radius:8px;border:1px solid #bae6fd;">
  <tr>
    <td style="padding:14px 16px;">
      <p style="margin:0;font-size:12px;color:#0369a1;line-height:1.5;">
        Cierre ejecutado por el proceso automatico programado. El efectivo fisico no fue
        contado al momento del cierre: la conciliacion corresponde al arqueo del siguiente turno.
      </p>
    </td>
  </tr>
</table>
@endif

<p style="margin:20px 0 0 0;font-size:12px;color:#94a3b8;line-height:1.5;">
  Los registros de cierre de caja son inmutables y no pueden modificarse una vez generados.
</p>
@endsection
