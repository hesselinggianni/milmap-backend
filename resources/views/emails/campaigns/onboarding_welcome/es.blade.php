@extends('emails.layout')

@section('title', 'Bienvenido a MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Bienvenido a bordo
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Bienvenido, ' . $name : 'Bienvenido' }} a MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="App de MilMap con mapa y pantalla de bienvenida" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Tu cuenta ya está lista. Durante los próximos <strong>7 días</strong> tendrás acceso
    completo a todas las funciones: mapas, planificación de rutas, misiones, análisis
    del terreno y comunicaciones de equipo cifradas. Después, seguirás usando las
    funciones básicas de forma gratuita.
  </p>

  <p style="margin:0 0 22px;">
    Nuestro consejo: abre la app y crea tu primer mapa. Mañana te enviaremos una breve
    guía para que empieces con buen pie.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Abrir MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Recibes este correo porque te registraste en MilMap.', 'unsubLabel' => 'Darse de baja de estos correos'])
@endsection
