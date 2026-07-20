@extends('emails.layout')

@section('title', 'Descuento por tiempo limitado')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#22c55e;">
    Oferta por tiempo limitado · Día 14
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', pásate' : 'Pásate' }} ahora — con descuento
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_discount.png" alt="Oferta por tiempo limitado en MilMap Premium" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    ¿Todavía dudas? Te damos un empujón extra: contrata ahora una suscripción a MilMap
    y aprovecha nuestra oferta por tiempo limitado. Así trabajarás sin límites, tanto
    sobre el terreno como en la oficina.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Mapas ilimitados &amp; sin conexión</li>
    <li>Misiones, equipos y comunicaciones cifradas</li>
    <li>Exportación de mapas de ruta y análisis del terreno</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#22c55e;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_yearly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Ver la oferta
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Recibes este correo porque te registraste en MilMap.', 'unsubLabel' => 'Darse de baja de estos correos'])
@endsection
