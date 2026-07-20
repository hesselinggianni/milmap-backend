@extends('emails.layout')

@section('title', 'Zeitlich begrenzter Rabatt')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#22c55e;">
    Zeitlich begrenzte Aktion · Tag 14
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', steig' : 'Steig' }} jetzt um — mit Rabatt
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_discount.png" alt="Zeitlich begrenzte Aktion auf MilMap Premium" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Zögerst du noch? Wir geben dir einen zusätzlichen Anstoß: Schließe jetzt ein
    MilMap-Abonnement ab und profitiere von unserer zeitlich begrenzten Aktion. So arbeitest
    du ohne Einschränkungen weiter — im Feld und im Büro.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Unbegrenzte &amp; Offline-Karten</li>
    <li>Missionen, Teams und verschlüsselte Kommunikation</li>
    <li>Routenkarten-Export und Terrainanalyse</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#22c55e;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_yearly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Zur Aktion
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Du erhältst diese E-Mail, weil du dich bei MilMap angemeldet hast.', 'unsubLabel' => 'Von diesen E-Mails abmelden'])
@endsection
