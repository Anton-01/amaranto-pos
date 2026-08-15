@extends('mail.layouts.corporate')

@section('title', 'Prueba de Conexion - Cronos POS')

@section('content')
{{-- Success banner: the message reaching an inbox is itself the proof, so the
     headline states the verdict before any detail. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
  <tr>
    <td align="center" style="padding-bottom:16px;">
      <table role="presentation" cellpadding="0" cellspacing="0">
        <tr>
          <td style="background-color:#dcfce7;border-radius:999px;padding:10px 18px;">
            <span style="font-size:12px;font-weight:700;color:#16a34a;letter-spacing:1px;">&#10003; CONEXION VERIFICADA</span>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<h2 style="margin:0 0 10px 0;font-size:20px;font-weight:700;color:#0f172a;text-align:center;">
  Prueba de Conexion Exitosa
</h2>

<p style="margin:0 0 24px 0;font-size:14px;color:#475569;line-height:1.6;text-align:center;">
  Si estas leyendo este mensaje, el servidor de Cronos POS logro autenticarse con el
  proveedor de correo y entregar el mensaje. La configuracion probada es funcional.
</p>

{{-- Diagnostic block: the values the transport actually dialled, so a failed
     delivery can be traced to a concrete port or sender without server access. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
  <tr>
    <td style="padding:20px;">
      <p style="margin:0 0 14px 0;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:1px;">
        Diagnostico del Envio
      </p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Fecha y Hora</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;font-weight:600;font-family:'Courier New',monospace;">{{ $sentAt }}</span>
            <br>
            <span style="font-size:11px;color:#94a3b8;">{{ $timezone }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Tipo de Proceso</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;font-weight:600;">{{ $processLabel }}</span>
            <br>
            <span style="font-size:11px;color:#94a3b8;font-family:'Courier New',monospace;">{{ $processType }}</span>
          </td>
        </tr>
        @if(!empty($transport['host']))
        <tr>
          <td style="padding:8px 0;@if(!empty($transport['encryption']))border-bottom:1px solid #e2e8f0;@endif">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Servidor SMTP</span>
          </td>
          <td style="padding:8px 0;@if(!empty($transport['encryption']))border-bottom:1px solid #e2e8f0;@endif text-align:right;">
            <span style="font-size:14px;color:#0f172a;font-weight:600;font-family:'Courier New',monospace;">
              {{ $transport['host'] }}@if(!empty($transport['port'])):{{ $transport['port'] }}@endif
            </span>
          </td>
        </tr>
        @endif
        @if(!empty($transport['encryption']))
        <tr>
          <td style="padding:8px 0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Cifrado</span>
          </td>
          <td style="padding:8px 0;text-align:right;">
            <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;background-color:#e0e7ff;color:#4338ca;">
              {{ strtoupper($transport['encryption']) }}
            </span>
          </td>
        </tr>
        @endif
      </table>
    </td>
  </tr>
</table>

@if(($transport['port'] ?? null) == 2525)
{{-- Reinforces the deployment rule documented in CONTEXT.md: cloud providers
     silently drop outbound traffic on 587, so a delivery through 2525 is the
     evidence that the alternate route is open. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background-color:#f0f9ff;border-radius:8px;border:1px solid #bae6fd;">
  <tr>
    <td style="padding:14px 16px;">
      <p style="margin:0;font-size:12px;color:#0369a1;line-height:1.5;">
        El envio salio por el <strong>puerto alterno 2525</strong>, requerido en servidores VPS
        donde el proveedor de nube bloquea el puerto 587 de salida por politica anti-spam.
      </p>
    </td>
  </tr>
</table>
@endif

<p style="margin:24px 0 0 0;font-size:12px;color:#94a3b8;line-height:1.5;">
  Este mensaje fue generado manualmente desde Configuracion &rarr; Notificaciones / Emails
  y no corresponde a ninguna operacion del negocio. Puedes eliminarlo.
</p>
@endsection
