@extends('mail.layouts.corporate')

@section('title', 'Restauracion de Contrasena - Cronos POS')

@section('content')
<h2 style="margin:0 0 16px 0;font-size:20px;font-weight:700;color:#0f172a;">
  Restauracion de Contrasena
</h2>

<p style="margin:0 0 8px 0;font-size:14px;color:#0f172a;line-height:1.6;">
  Estimado/a {{ $userName }},
</p>

<p style="margin:0 0 20px 0;font-size:14px;color:#475569;line-height:1.6;">
  Un administrador del sistema ha iniciado un proceso de cambio de clave para su cuenta.
  Utilice el siguiente enlace para establecer una nueva contrasena de acceso.
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <tr>
    <td align="center" style="padding:8px 0 24px 0;">
      <a href="{{ $resetUrl }}"
         style="display:inline-block;background-color:#4f46e5;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;letter-spacing:0.3px;"
         target="_blank">
        Restaurar Contrasena
      </a>
    </td>
  </tr>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
  <tr>
    <td style="padding:14px 16px;">
      <p style="margin:0;font-size:12px;color:#475569;line-height:1.5;">
        <strong style="color:#0f172a;">Vigencia del enlace:</strong> 4 horas a partir de su generacion.
        Transcurrido ese plazo, sera necesario solicitar un nuevo enlace al administrador.
      </p>
    </td>
  </tr>
</table>

<p style="margin:20px 0 0 0;font-size:12px;color:#94a3b8;line-height:1.5;">
  Si usted no solicito este cambio o desconoce esta accion, ignore este correo.
  Su contrasena actual permanecera sin cambios.
</p>
@endsection
