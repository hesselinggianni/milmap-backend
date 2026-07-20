@extends('emails.layout')

@section('title', 'MilMap sur le terrain')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Étude de cas · Jour 5
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    Comment les unités utilisent MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_case.png" alt="Carte d'itinéraire avec points de contrôle dans MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Une unité de reconnaissance a utilisé MilMap lors d'un exercice en montagne pour
    planifier des itinéraires, emporter des cartes d'itinéraire en PDF et naviguer hors
    ligne sans couverture réseau.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Cartes d'itinéraire créées et imprimées avec des coordonnées MGRS.</li>
    <li>Analyse de terrain des cols et des camps.</li>
    <li>Cartes hors ligne pour une utilisation sans connexion.</li>
    <li>Module Missions pour la planification selon l'O-group.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($siteUrl ?? 'https://milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Lire d'autres témoignages
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Vous recevez cet e-mail car vous vous êtes inscrit sur MilMap.', 'unsubLabel' => 'Se désabonner de ces e-mails'])
@endsection
