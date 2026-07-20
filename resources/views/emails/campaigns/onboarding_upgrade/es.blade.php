@extends('emails.layout')

@section('title', 'Actualiza a Premium')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    Tu prueba está a punto de terminar · Día 7
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', conserva' : 'Conserva' }} todas tus funciones
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="Pantalla de actualización a MilMap Premium" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Tus 7 días gratuitos con acceso completo están llegando a su fin. Sin una
    suscripción seguirás usando la versión básica de forma gratuita (máximo 5 mapas),
    pero perderás, entre otras cosas:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Misiones, chat y el MEDEVAC 9-liner</li>
    <li>Mapas ilimitados &amp; sin conexión + integración meteorológica</li>
    <li>Exportación de mapas de ruta, equipos y ubicación en vivo compartida</li>
  </ul>

  <p style="margin:0 0 22px;">
    Desde <strong>4,99&nbsp;€/mes</strong> conservas todo. Cancelable en cualquier momento.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Actualizar a Premium
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Recibes este correo porque te registraste en MilMap.', 'unsubLabel' => 'Darse de baja de estos correos'])
@endsection
