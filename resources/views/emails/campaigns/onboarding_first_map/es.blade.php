@extends('emails.layout')

@section('title', 'Crea tu primer mapa')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Primeros pasos · Día 1
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', crea' : 'Crea' }} tu primer mapa
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_first_map.png" alt="Creación de un nuevo mapa con coordenadas MGRS" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    En pocos minutos tendrás listo tu primer mapa operativo. Así es como se hace:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Abre MilMap y pulsa en <strong>Nuevo mapa</strong>.</li>
    <li>Busca tu zona y coloca puntos con coordenadas MGRS.</li>
    <li>Importa tus tracks GPX/KML existentes si los tienes.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Crear un mapa
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Recibes este correo porque te registraste en MilMap.', 'unsubLabel' => 'Darse de baja de estos correos'])
@endsection
