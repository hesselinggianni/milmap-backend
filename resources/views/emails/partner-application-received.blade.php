@extends('emails.layout')
@section('title', 'Je partneraanmelding is ontvangen')
@section('body')

  <h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;">
    Bedankt voor je aanmelding als Milmap-partner
  </h1>

  <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    We hebben je aanmelding{{ $partner->company_name ? ' voor ' : '' }}<strong style="color:#f8fafc;">{{ $partner->company_name }}</strong> ontvangen.
    We beoordelen elke aanvraag handmatig; je hoort meestal binnen twee werkdagen van ons.
  </p>

  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Zodra je bent goedgekeurd ontvang je je persoonlijke referral-link
    (<strong style="color:#f8fafc;">{{ $partner->referral_code }}</strong>) waarmee je publiek korting geeft
    en jij commissie ontvangt over elke betaling.
  </p>

  @if($setupUrl)
  <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Er is een Milmap-account voor je aangemaakt. Stel eerst je wachtwoord in, daarmee log je straks
    ook in op het partnerportaal:
  </p>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
    <tr>
      <td style="border-radius:10px;background:#2b7fff;">
        <a href="{{ $setupUrl }}"
           style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
          Wachtwoord instellen
        </a>
      </td>
    </tr>
  </table>
  @endif

  <p style="margin:0;font-size:13px;line-height:1.7;color:#94a3b8;">
    Vragen? Beantwoord deze mail, dan denken we mee.
  </p>

@endsection
