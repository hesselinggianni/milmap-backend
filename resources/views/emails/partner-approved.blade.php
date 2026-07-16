@extends('emails.layout')
@section('title', 'Je bent nu Milmap-partner')
@section('body')

  <h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;">
    Welkom als Milmap-partner 🎉
  </h1>

  <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Je aanmelding is goedgekeurd. Vanaf nu geeft jouw link nieuwe gebruikers
    <strong style="color:#f8fafc;">{{ rtrim(rtrim(number_format($partner->discount_rate, 2, '.', ''), '0'), '.') }}% korting</strong>
    en ontvang jij
    <strong style="color:#f8fafc;">{{ rtrim(rtrim(number_format($partner->commission_rate, 2, '.', ''), '0'), '.') }}% commissie</strong>
    over hun betalingen in het eerste abonnementsjaar (maximaal 12 maanden per gebruiker).
  </p>

  {{-- Overeenkomst eerst --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 20px;background:#1a2433;border:1px solid #2a3a52;border-radius:10px;">
    <tr><td style="padding:16px 18px;">
      <p style="margin:0 0 6px;font-size:13px;color:#94a3b8;">Nog één stap</p>
      <p style="margin:0;font-size:14px;line-height:1.6;color:#f8fafc;">
        Accepteer eerst de <strong>partnerovereenkomst</strong> in het portaal
        (je bevestigt per e-mail). Daarna staat je persoonlijke referral-link
        (code <strong style="color:#2b7fff;">{{ $partner->referral_code }}</strong>) klaar om te delen.
      </p>
    </td></tr>
  </table>

  <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Volgende stappen:
  </p>
  <ol style="margin:0 0 20px;padding-left:20px;font-size:14px;line-height:1.9;color:#cbd5e1;">
    <li>Log in op het partnerportaal en accepteer de partnerovereenkomst (bevestiging per e-mail).</li>
    <li>Koppel je bankrekening via Stripe (voor uitbetalingen).</li>
    <li>Deel je link met je doelgroep — uitbetaling volgt automatisch elke maand (vanaf €10).</li>
  </ol>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
    <tr>
      <td style="border-radius:10px;background:#2b7fff;">
        <a href="{{ rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/') }}/dashboard"
           style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
          Naar het partnerportaal
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:12px;line-height:1.7;color:#64748b;">
    Let op: je bent zelf verantwoordelijk voor eventuele BTW-aangifte over je commissie-inkomsten.
  </p>

@endsection
