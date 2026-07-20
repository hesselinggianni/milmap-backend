@extends('emails.layout')

@section('title', 'Bem-vindo ao MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Bem-vindo a bordo
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Bem-vindo, ' . $name : 'Bem-vindo' }} ao MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="App MilMap com mapa e ecrã de boas-vindas" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    A tua conta está pronta. Nos próximos <strong>7 dias</strong> tens acesso total a
    todas as funcionalidades — mapas, planeamento de rotas, missões, análise de terreno e comunicações
    de equipa encriptadas. Depois disso, continuas gratuitamente com as funcionalidades básicas.
  </p>

  <p style="margin:0 0 22px;">
    A nossa dica: abre a app e cria o teu primeiro mapa. Amanhã enviamos-te um breve
    guia para começares rapidamente.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Abrir o MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Recebes este e-mail porque te registaste no MilMap.', 'unsubLabel' => 'Cancelar subscrição destes e-mails'])
@endsection
