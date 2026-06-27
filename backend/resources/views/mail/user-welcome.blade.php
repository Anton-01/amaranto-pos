@extends('mail.layouts.corporate')

@section('title', 'Bienvenido a Cronos POS')

@section('content')
<tr>
  <td style="padding:32px 32px 0;">
    <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#0f172a;">Bienvenido a Cronos POS</h1>
    <p style="margin:0;font-size:14px;color:#475569;line-height:1.6;">
      Estimado(a) <strong>{{ $userName }}</strong>,
    </p>
  </td>
</tr>

<tr>
  <td style="padding:16px 32px 0;">
    <p style="margin:0 0 16px;font-size:14px;color:#475569;line-height:1.6;">
      Se ha creado tu cuenta de operador en el sistema de Punto de Venta. A continuacion encontraras tus credenciales de acceso inicial. Por seguridad, te recomendamos cambiar tu contrasena en el primer inicio de sesion.
    </p>
  </td>
</tr>

<tr>
  <td style="padding:0 32px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;border-radius:8px;border:1px solid #e2e8f0;">
      <tr>
        <td style="padding:20px 24px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td style="padding-bottom:12px;">
                <span style="display:block;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Correo de acceso</span>
                <span style="display:block;font-size:15px;font-weight:600;color:#0f172a;margin-top:4px;">{{ $userEmail }}</span>
              </td>
            </tr>
            <tr>
              <td style="border-top:1px solid #e2e8f0;padding-top:12px;">
                <span style="display:block;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Contrasena temporal</span>
                <span style="display:block;font-size:15px;font-weight:700;color:#4f46e5;margin-top:4px;font-family:'Courier New',Courier,monospace;letter-spacing:1px;">{{ $temporaryPassword }}</span>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </td>
</tr>

<tr>
  <td style="padding:24px 32px 0;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
      <tr>
        <td style="background-color:#4f46e5;border-radius:8px;">
          <a href="{{ $loginUrl }}" target="_blank" style="display:inline-block;padding:12px 32px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">Iniciar Sesion</a>
        </td>
      </tr>
    </table>
  </td>
</tr>

<tr>
  <td style="padding:16px 32px 0;">
    <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.5;text-align:center;">
      Si el boton no funciona, copia y pega la siguiente URL en tu navegador:<br>
      <a href="{{ $loginUrl }}" style="color:#4f46e5;text-decoration:underline;word-break:break-all;">{{ $loginUrl }}</a>
    </p>
  </td>
</tr>

<tr>
  <td style="padding:24px 32px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fef3c7;border-radius:8px;border:1px solid #fcd34d;">
      <tr>
        <td style="padding:16px 20px;">
          <p style="margin:0;font-size:13px;color:#92400e;line-height:1.5;">
            <strong>Importante:</strong> Esta contrasena es temporal y fue generada automaticamente por el sistema. Te recomendamos cambiarla desde la seccion "Mi Perfil" una vez que inicies sesion.
          </p>
        </td>
      </tr>
    </table>
  </td>
</tr>
@endsection
