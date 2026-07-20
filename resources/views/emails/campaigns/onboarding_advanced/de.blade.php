@extends('emails.layout')

@section('title', 'Erweiterte Funktionen')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Weiter geht's · Tag 3
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    Die Stärken von MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_advanced.png" alt="Missionsbriefing und Geländeprofil in MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . ', du' : 'Du' }} kannst viel mehr als nur Karten zeichnen. Diese Funktionen
    machen im Feld wirklich den Unterschied:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>Missionen</strong> — planen, briefen und zusammenarbeiten nach SMEAC.</li>
    <li><strong>MEDEVAC 9-liner</strong> — schnell und strukturiert anfordern.</li>
    <li><strong>Terrainanalyse</strong> — Höhenprofil und Sichtlinien.</li>
    <li><strong>Offline-Karten</strong> — weiterarbeiten ohne Verbindung.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Jetzt ausprobieren
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Du erhältst diese E-Mail, weil du dich bei MilMap angemeldet hast.', 'unsubLabel' => 'Von diesen E-Mails abmelden'])
@endsection
