@extends('emails.layout')

@section('title', 'MilMap im Einsatz')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Kundenbeispiel · Tag 5
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    So nutzen Einheiten MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_case.png" alt="Routenkarte mit Checkpoints in MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Eine Aufklärungseinheit nutzte MilMap während einer Bergübung, um Routen zu planen,
    PDF-Routenkarten mitzunehmen und offline ohne Netzabdeckung zu navigieren.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Routenkarten mit MGRS-Koordinaten erstellt und ausgedruckt.</li>
    <li>Terrainanalyse von Bergpässen und Lagern.</li>
    <li>Offline-Karten für die Nutzung ohne Verbindung.</li>
    <li>Missions-Modul für die O-group-Planung.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($siteUrl ?? 'https://milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Weitere Geschichten lesen
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Du erhältst diese E-Mail, weil du dich bei MilMap angemeldet hast.', 'unsubLabel' => 'Von diesen E-Mails abmelden'])
@endsection
