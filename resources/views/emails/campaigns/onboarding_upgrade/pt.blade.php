@extends('emails.layout')

@section('title', 'Atualiza para Premium')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    O teu período de teste termina · Dia 7
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', mantém' : 'Mantém' }} todas as tuas funcionalidades
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="Ecrã de atualização para o MilMap Premium" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Os teus 7 dias gratuitos com acesso total estão a terminar. Sem subscrição, continuas gratuitamente
    com o essencial (máx. 5 mapas), mas perdes, entre outras coisas:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Missões, chat e o MEDEVAC 9-liner</li>
    <li>Mapas ilimitados &amp; offline + integração meteorológica</li>
    <li>Exportação de cartas de rota, equipas e partilha de localização em direto</li>
  </ul>

  <p style="margin:0 0 22px;">
    A partir de <strong>€4,99/mês</strong> mantens tudo. Podes cancelar quando quiseres.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Atualizar para Premium
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Recebes este e-mail porque te registaste no MilMap.', 'unsubLabel' => 'Cancelar subscrição destes e-mails'])
@endsection
