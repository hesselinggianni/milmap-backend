@extends('emails.layout')

@section('title', 'Fonctionnalités avancées')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Allez plus loin · Jour 3
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    La puissance de MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_advanced.png" alt="Briefing de mission et profil de terrain dans MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . ', vous' : 'Vous' }} pouvez faire bien plus que dessiner des cartes.
    Ces fonctionnalités font vraiment la différence sur le terrain :
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>Missions</strong> — planifiez, briefez et collaborez selon la méthode SMEAC.</li>
    <li><strong>MEDEVAC 9-liner</strong> — demandez rapidement et de façon structurée.</li>
    <li><strong>Analyse de terrain</strong> — profil altimétrique et lignes de vue.</li>
    <li><strong>Cartes hors ligne</strong> — continuez à travailler sans connexion.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Essayez-le
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Vous recevez cet e-mail car vous vous êtes inscrit sur MilMap.', 'unsubLabel' => 'Se désabonner de ces e-mails'])
@endsection
