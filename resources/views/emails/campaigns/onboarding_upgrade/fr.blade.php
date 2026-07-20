@extends('emails.layout')

@section('title', 'Passez à Premium')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    Votre essai se termine · Jour 7
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', conservez' : 'Conservez' }} toutes vos fonctionnalités
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="Écran de passage à MilMap Premium" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Vos 7 jours gratuits avec accès complet touchent à leur fin. Sans abonnement, vous
    continuerez gratuitement avec les fonctions de base (5 cartes maximum), mais vous
    perdrez notamment :
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Les missions, le chat et le MEDEVAC 9-liner</li>
    <li>Les cartes illimitées &amp; hors ligne + l'intégration météo</li>
    <li>L'export de cartes d'itinéraire, les équipes et le partage de position en direct</li>
  </ul>

  <p style="margin:0 0 22px;">
    À partir de <strong>4,99 €/mois</strong>, vous conservez tout. Résiliable à tout moment.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Passer à Premium
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
