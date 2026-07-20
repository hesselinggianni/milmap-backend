@extends('emails.layout')

@section('title', 'Bienvenue sur MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Bienvenue à bord
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Bienvenue, ' . $name : 'Bienvenue' }} sur MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="Application MilMap avec carte et écran de bienvenue" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Votre compte est prêt. Pendant les <strong>7 prochains jours</strong>, vous avez un accès
    complet à toutes les fonctionnalités — cartes, planification d'itinéraires, missions,
    analyse de terrain et communications d'équipe chiffrées. Ensuite, vous continuerez
    gratuitement avec les fonctions de base.
  </p>

  <p style="margin:0 0 22px;">
    Notre conseil : ouvrez l'application et créez votre première carte. Demain, nous vous
    enverrons un court guide pour démarrer rapidement.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Ouvrir MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
