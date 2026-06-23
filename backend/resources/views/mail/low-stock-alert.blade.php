@extends('mail.layouts.corporate')

@section('title', 'Alerta de Stock Critico - Cronos POS')

@section('content')
<h2 style="margin:0 0 16px 0;font-size:20px;font-weight:700;color:#0f172a;">
  Alerta de Stock Critico
</h2>

<p style="margin:0 0 20px 0;font-size:14px;color:#475569;line-height:1.6;">
  Los siguientes productos se encuentran por debajo de su nivel minimo de inventario.
  Se recomienda gestionar la orden de compra o abastecimiento correspondiente a la brevedad.
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
  <thead>
    <tr>
      <th style="background-color:#f1f5f9;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #e2e8f0;">
        SKU
      </th>
      <th style="background-color:#f1f5f9;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #e2e8f0;">
        Producto
      </th>
      <th style="background-color:#f1f5f9;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #e2e8f0;">
        Categoria
      </th>
      <th style="background-color:#f1f5f9;padding:10px 12px;text-align:center;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #e2e8f0;">
        Minimo
      </th>
      <th style="background-color:#f1f5f9;padding:10px 12px;text-align:center;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #e2e8f0;">
        Actual
      </th>
    </tr>
  </thead>
  <tbody>
    @foreach($products as $product)
    <tr>
      <td style="padding:10px 12px;font-size:13px;color:#0f172a;font-family:'Courier New',monospace;font-weight:600;border-bottom:1px solid #f1f5f9;">
        {{ $product['sku'] }}
      </td>
      <td style="padding:10px 12px;font-size:13px;color:#0f172a;border-bottom:1px solid #f1f5f9;">
        {{ $product['name'] }}
      </td>
      <td style="padding:10px 12px;font-size:13px;color:#475569;border-bottom:1px solid #f1f5f9;">
        {{ $product['category'] ?? 'Sin categoria' }}
      </td>
      <td style="padding:10px 12px;font-size:13px;color:#475569;text-align:center;border-bottom:1px solid #f1f5f9;">
        {{ $product['minimum_stock'] }}
      </td>
      <td style="padding:10px 12px;font-size:13px;font-weight:700;text-align:center;border-bottom:1px solid #f1f5f9;color:{{ $product['current_stock'] <= 0 ? '#dc2626' : '#d97706' }};">
        {{ $product['current_stock'] }}
      </td>
    </tr>
    @endforeach
  </tbody>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background-color:#fffbeb;border-radius:8px;border:1px solid #fde68a;">
  <tr>
    <td style="padding:14px 16px;">
      <p style="margin:0;font-size:12px;color:#92400e;line-height:1.5;">
        <strong>{{ count($products) }} producto(s)</strong> requieren atencion inmediata.
        Acceda al modulo de Almacen en el sistema para registrar las entradas de inventario.
      </p>
    </td>
  </tr>
</table>
@endsection
