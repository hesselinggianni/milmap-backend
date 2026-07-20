@extends('emails.layout')

@section('title', 'Funcionalidades avançadas')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Vai mais longe · Dia 3
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    O poder do MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_advanced.png" alt="Briefing de missão e perfil de terreno no MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . ', podes' : 'Podes' }} fazer muito mais do que desenhar mapas. Estas funcionalidades
    fazem realmente a diferença no terreno:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>Missões</strong> — planeia, faz o briefing e colabora seguindo o SMEAC.</li>
    <li><strong>MEDEVAC 9-liner</strong> — pedidos rápidos e estruturados.</li>
    <li><strong>Análise de terreno</strong> — perfil de altitude e linhas de visão.</li>
    <li><strong>Mapas offline</strong> — continua a trabalhar sem ligação.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Experimenta agora
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Recebes este e-mail porque te registaste no MilMap.', 'unsubLabel' => 'Cancelar subscrição destes e-mails'])
@endsection
