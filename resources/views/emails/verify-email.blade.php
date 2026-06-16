@extends('emails.layout')
@section('title', 'Bevestig je e-mailadres — Milmap')
@section('body')

  {{-- Header row --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#1a2433;border:1px solid #2a3a52;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <rect x="2" y="4" width="20" height="16" rx="2"/>
            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          Bevestig je e-mailadres
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">Eén klik en je bent klaar</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  @if($firstName)
  <p style="margin:0 0 16px;font-size:15px;color:#f8fafc;font-weight:600;">
    Hoi {{ $firstName }},
  </p>
  @endif

  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Welkom bij <strong style="color:#f8fafc;">Milmap</strong>. Bevestig je e-mailadres door op de knop
    hieronder te klikken. Zo weten we zeker dat dit adres echt van jou is. De link is
    <strong style="color:#f8fafc;">7 dagen</strong> geldig.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="border-radius:8px;background:#2b7fff;">
        <a href="{{ $verificationUrl }}"
           style="display:inline-block;height:48px;padding:0 28px;line-height:48px;
                  font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">
          E-mailadres bevestigen →
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px;font-size:12px;color:#7e8a9c;word-break:break-all;line-height:1.6;">
    Werkt de knop niet? Kopieer deze URL:<br>
    <span style="color:#2b7fff;">{{ $verificationUrl }}</span>
  </p>

  <div style="height:1px;background:#1e293b;margin:0 0 20px;"></div>

  <p style="margin:0 0 8px;font-size:13px;line-height:1.7;color:#94a3b8;">
    Je kunt Milmap de eerste 24 uur na aanmelden gewoon gebruiken. Bevestig je je e-mailadres niet,
    dan vragen we je daarna eerst te bevestigen voordat je verder kunt. Accounts die na 90 dagen
    niet zijn bevestigd, worden automatisch gearchiveerd.
  </p>
  <p style="margin:0;font-size:13px;line-height:1.7;color:#7e8a9c;">
    Heb je je niet aangemeld bij Milmap? Dan kun je deze e-mail negeren.
  </p>

@endsection
