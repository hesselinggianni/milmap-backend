@extends('emails.layout')

@section('title', 'Upgrade auf Premium')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    Deine Testphase endet · Tag 7
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', behalte' : 'Behalte' }} alle deine Funktionen
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="MilMap Premium Upgrade-Bildschirm" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Deine 7 kostenlosen Tage mit vollem Zugriff laufen ab. Ohne Abonnement nutzt du weiterhin
    kostenlos die Grundfunktionen (max. 5 Karten), verlierst dabei aber unter anderem:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Missionen, Chat und den MEDEVAC 9-liner</li>
    <li>Unbegrenzte &amp; Offline-Karten + Wetterintegration</li>
    <li>Routenkarten-Export, Teams und Live-Standortfreigabe</li>
  </ul>

  <p style="margin:0 0 22px;">
    Ab <strong>4,99 €/Monat</strong> behältst du alles. Jederzeit kündbar.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Auf Premium upgraden
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Du erhältst diese E-Mail, weil du dich bei MilMap angemeldet hast.', 'unsubLabel' => 'Von diesen E-Mails abmelden'])
@endsection
