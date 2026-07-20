@extends('emails.layout')

@section('title', 'Erstelle deine erste Karte')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Erste Schritte · Tag 1
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', erstelle' : 'Erstelle' }} deine erste Karte
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_first_map.png" alt="Neue Karte erstellen mit MGRS-Koordinaten" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    In wenigen Minuten hast du deine erste operative Karte fertig. So geht's:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Öffne MilMap und klicke auf <strong>Neue Karte</strong>.</li>
    <li>Suche dein Gebiet und setze Punkte mit MGRS-Koordinaten.</li>
    <li>Importiere vorhandene GPX/KML-Tracks, falls du welche hast.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Karte erstellen
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Du erhältst diese E-Mail, weil du dich bei MilMap angemeldet hast.', 'unsubLabel' => 'Von diesen E-Mails abmelden'])
@endsection
