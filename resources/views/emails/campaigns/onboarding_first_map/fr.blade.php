@extends('emails.layout')

@section('title', 'Créez votre première carte')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Pour bien démarrer · Jour 1
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', créez' : 'Créez' }} votre première carte
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_first_map.png" alt="Création d'une nouvelle carte avec des coordonnées MGRS" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    En quelques minutes, votre première carte opérationnelle sera prête. Voici comment
    procéder :
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Ouvrez MilMap et cliquez sur <strong>Nouvelle carte</strong>.</li>
    <li>Recherchez votre zone et placez des points avec des coordonnées MGRS.</li>
    <li>Importez vos traces GPX/KML existantes si vous en avez.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Créer une carte
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Vous recevez cet e-mail car vous vous êtes inscrit sur MilMap.', 'unsubLabel' => 'Se désabonner de ces e-mails'])
@endsection
