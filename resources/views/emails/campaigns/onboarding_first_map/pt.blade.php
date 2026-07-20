@extends('emails.layout')

@section('title', 'Cria o teu primeiro mapa')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Primeiros passos · Dia 1
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', cria' : 'Cria' }} o teu primeiro mapa
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_first_map.png" alt="Criar um novo mapa com coordenadas MGRS" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Em poucos minutos terás o teu primeiro mapa operacional pronto. É assim que se faz:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Abre o MilMap e clica em <strong>Novo mapa</strong>.</li>
    <li>Procura a tua área e coloca pontos com coordenadas MGRS.</li>
    <li>Importa tracks GPX/KML existentes, se os tiveres.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Criar um mapa
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Recebes este e-mail porque te registaste no MilMap.', 'unsubLabel' => 'Cancelar subscrição destes e-mails'])
@endsection
