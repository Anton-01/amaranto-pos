@extends('mail.layouts.corporate')

@section('title', 'Movimiento de Caja Chica - Cronos POS')

@section('content')
<h2 style="margin:0 0 16px 0;font-size:20px;font-weight:700;color:#0f172a;">
  Notificacion de Movimiento de Caja Chica
</h2>

<p style="margin:0 0 20px 0;font-size:14px;color:#475569;line-height:1.6;">
  Se ha registrado un retiro de caja chica en el sistema. A continuacion se detallan
  los datos del movimiento para su revision y control administrativo.
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
  <tr>
    <td style="padding:20px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Monto del Retiro</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:18px;font-weight:700;color:#dc2626;">${{ number_format($amount, 2) }} MXN</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Concepto</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;">{{ $reasonLabel }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Justificacion</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:13px;color:#0f172a;">{{ $description }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Operador</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;">{{ $operatorName }}</span>
            <br><span style="font-size:12px;color:#94a3b8;">{{ $operatorEmail }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Fecha y Hora</span>
          </td>
          <td style="padding:8px 0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;">{{ $timestamp }}</span>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

@if(!empty($sealHash))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;background-color:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
  <tr>
    <td style="padding:12px 16px;">
      <p style="margin:0;font-size:11px;color:#94a3b8;line-height:1.5;">
        <strong style="color:#475569;">Sello de integridad:</strong>
        <span style="font-family:'Courier New',monospace;font-size:10px;word-break:break-all;">{{ $sealHash }}</span>
      </p>
    </td>
  </tr>
</table>
@endif

<p style="margin:20px 0 0 0;font-size:12px;color:#94a3b8;line-height:1.5;">
  Este registro es inmutable y ha sido sellado criptograficamente.
  Para verificar la integridad, acceda al modulo de Caja Chica en el sistema.
</p>
@endsection
