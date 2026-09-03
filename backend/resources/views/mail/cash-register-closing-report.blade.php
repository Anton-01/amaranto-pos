@extends('mail.layouts.corporate')

@section('title', 'Reporte de Cierre de Caja - Cronos POS')

@section('content')
@php
    /*
     * Header typography, declared once and reused by every line of the
     * letterhead.
     *
     * WHY A SHARED STRING AND NOT A LITERAL PER ELEMENT. The defect this fixes
     * was precisely one element drifting away from the rest: the RFC and the
     * folio carried a 'Courier New' monospace stack while everything around
     * them was sans-serif, so the two most formal identifiers on the document
     * were the two that looked pasted in from another file. Building every line
     * from the same string makes that drift impossible rather than merely
     * unlikely.
     *
     * The stack matches the <body> declaration in mail.layouts.corporate
     * character for character, so the header is the same typeface as the report
     * beneath it.
     */
    $sansStack = "'Inter','Segoe UI',Helvetica,Arial,sans-serif";

    /*
     * WHY EVERY ELEMENT REPEATS THE FONT INLINE INSTEAD OF INHERITING IT.
     * Inheritance from <body> is not dependable in email. Outlook renders
     * through the Word engine, which resets the font on block-level elements,
     * and Gmail rewrites the document before display and drops rules it cannot
     * resolve. A <p> that relies on inheritance therefore falls back to the
     * client default — Times New Roman in Outlook — in exactly the clients this
     * report is read in. The stack has to travel on the element itself.
     */
    $metaBase = "font-size:12px;color:#475569;line-height:1.5;font-family:{$sansStack};";

    /*
     * One line style for RFC, Direccion and Tel. Sharing it is what guarantees
     * the three keep identical hierarchy, size, colour and spacing: they cannot
     * diverge without someone editing this single declaration.
     */
    $metaLine = "margin:0 0 2px 0;{$metaBase}";
    $metaLabel = "font-weight:600;font-family:{$sansStack};";
    $metaValue = "font-weight:400;font-family:{$sansStack};";
@endphp
{{-- Fiscal letterhead: makes the email itself the formal document, replacing
     the PDF attachment this report used to carry. --}}
@if(!empty($fiscal))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #4f46e5;padding-bottom:14px;margin-bottom:20px;">
  <tr>
    <td style="font-family:{!! $sansStack !!};">
      <p style="margin:0 0 4px 0;font-size:16px;font-weight:700;color:#0f172a;letter-spacing:0.2px;font-family:{!! $sansStack !!};">
        {{ $fiscal['business_name'] ?? 'Cronos POS' }}
      </p>
      @if(!empty($fiscal['rfc']))
      <p style="{!! $metaLine !!}">
        <span style="{!! $metaLabel !!}">RFC:</span>
        <span style="{!! $metaValue !!}">{{ $fiscal['rfc'] }}</span>
      </p>
      @endif
      @if(!empty($fiscal['address']))
      <p style="{!! $metaLine !!}">
        <span style="{!! $metaLabel !!}">Dirección:</span>
        <span style="{!! $metaValue !!}">{{ $fiscal['address'] }}@if(!empty($fiscal['city'])), {{ $fiscal['city'] }}@endif</span>
      </p>
      @endif
      @if(!empty($fiscal['phone']))
      <p style="{!! $metaLine !!}">
        <span style="{!! $metaLabel !!}">Tel:</span>
        <span style="{!! $metaValue !!}">{{ $fiscal['phone'] }}</span>
      </p>
      @endif
    </td>
  </tr>
</table>
@endif

<h2 style="margin:0 0 6px 0;font-size:20px;font-weight:700;color:#0f172a;font-family:{!! $sansStack !!};">
  Reporte de Cierre de Caja
</h2>
{{-- The folio keeps its weight, its darker colour and the 0.5px letter-spacing
     that make an alphanumeric code easy to read aloud. None of those needs a
     different typeface: legibility of a code comes from tracking and weight,
     not from switching to monospace. --}}
<p style="margin:0 0 20px 0;font-size:12px;color:#94a3b8;letter-spacing:0.5px;font-family:{!! $sansStack !!};">
  FOLIO <span style="font-weight:700;color:#475569;font-family:{!! $sansStack !!};">{{ $folio }}</span>
</p>

<p style="margin:0 0 20px 0;font-size:14px;color:#475569;line-height:1.6;font-family:{!! $sansStack !!};">
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
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;font-family:{!! $sansStack !!};">Operador</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;font-weight:600;font-family:{!! $sansStack !!};">{{ $operatorName }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;font-family:{!! $sansStack !!};">Fecha del Cierre</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="font-size:14px;color:#0f172a;font-family:{!! $sansStack !!};">{{ $closingDate }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;font-family:{!! $sansStack !!};">Modalidad</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #e2e8f0;text-align:right;">
            <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;
              {{ $isAutomated ? 'background-color:#e0e7ff;color:#4338ca;' : 'background-color:#dcfce7;color:#16a34a;' }}font-family:{!! $sansStack !!};">
              {{ $isAutomated ? 'CIERRE AUTOMATICO' : 'CIERRE MANUAL' }}
            </span>
          </td>
        </tr>
        <tr>
          <td style="padding:12px 0 0 0;">
            <span style="font-size:12px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;font-family:{!! $sansStack !!};">Total Registrado</span>
          </td>
          <td style="padding:12px 0 0 0;text-align:right;">
            <span style="font-size:20px;font-weight:700;color:#0f172a;font-family:{!! $sansStack !!};">${{ number_format($totalAmount, 2) }}</span>
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
      <p style="margin:0 0 10px 0;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;font-family:{!! $sansStack !!};">
        Desglose por Metodo de Pago
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        @foreach($paymentBreakdown as $method => $total)
        <tr>
          <td style="padding:6px 0;font-size:13px;color:#475569;font-family:{!! $sansStack !!};">{{ $method }}</td>
          <td style="padding:6px 0;font-size:13px;color:#0f172a;font-weight:600;text-align:right;font-family:{!! $sansStack !!};">${{ number_format($total, 2) }}</td>
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
      <p style="margin:0;font-size:12px;color:#0369a1;line-height:1.5;font-family:{!! $sansStack !!};">
        Cierre ejecutado por el proceso automatico programado. El efectivo fisico no fue
        contado al momento del cierre: la conciliacion corresponde al arqueo del siguiente turno.
      </p>
    </td>
  </tr>
</table>
@endif

<p style="margin:20px 0 0 0;font-size:12px;color:#94a3b8;line-height:1.5;font-family:{!! $sansStack !!};">
  Los registros de cierre de caja son inmutables y no pueden modificarse una vez generados.
</p>
@endsection
