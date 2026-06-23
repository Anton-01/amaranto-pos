<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>@yield('title', 'Cronos POS')</title>
<!--[if mso]>
<style type="text/css">
  table { border-collapse: collapse; }
  td { font-family: Arial, sans-serif; }
</style>
<![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f8fafc;font-family:'Inter','Segoe UI',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        {{-- Header --}}
        <tr>
          <td align="center" style="padding-bottom:24px;">
            <table role="presentation" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background-color:#4f46e5;border-radius:8px;padding:10px 20px;">
                  <span style="color:#ffffff;font-weight:700;font-size:16px;letter-spacing:0.5px;">CRONOS POS</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Body Card --}}
        <tr>
          <td>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;">
              <tr>
                <td style="padding:32px;">
                  @yield('content')
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Footer --}}
        <tr>
          <td style="padding-top:24px;text-align:center;">
            <p style="margin:0 0 6px 0;font-size:12px;color:#94a3b8;line-height:1.5;">
              Este es un correo automatizado generado por el sistema Cronos POS.
            </p>
            <p style="margin:0 0 6px 0;font-size:12px;color:#94a3b8;line-height:1.5;">
              No responda a este mensaje. Para consultas, contacte al administrador del sistema.
            </p>
            @if(isset($footerFiscal))
            <p style="margin:12px 0 0 0;font-size:11px;color:#94a3b8;line-height:1.5;">
              {{ $footerFiscal['business_name'] ?? '' }}
              @if(!empty($footerFiscal['rfc'])) &middot; RFC: {{ $footerFiscal['rfc'] }} @endif
              @if(!empty($footerFiscal['address'])) <br>{{ $footerFiscal['address'] }} @endif
              @if(!empty($footerFiscal['city'])) , {{ $footerFiscal['city'] }} @endif
              @if(!empty($footerFiscal['phone'])) &middot; Tel: {{ $footerFiscal['phone'] }} @endif
            </p>
            @endif
            <p style="margin:12px 0 0 0;font-size:11px;color:#cbd5e1;">
              &copy; {{ date('Y') }} Cronos POS. Todos los derechos reservados.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
