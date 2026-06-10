@extends('emails.layout')
@section('title', 'Welkom bij Milmap — Stel je wachtwoord in')
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
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          Betaling geslaagd!
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">Welkom bij Milmap {{ $planLabel }}</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  {{-- Greeting --}}
  @if($firstName)
  <p style="margin:0 0 16px;font-size:15px;color:#f8fafc;font-weight:600;">
    Hoi {{ $firstName }},
  </p>
  @endif

  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Je betaling is bevestigd en je <strong style="color:#f8fafc;">Milmap {{ $planLabel }}</strong>-abonnement
    is direct actief. Er is een account aangemaakt op dit e-mailadres.
  </p>

  {{-- Plan badge --}}
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="padding:12px 18px;background:#1a2433;border:1px solid #2a3a52;border-radius:10px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="vertical-align:middle;padding-right:10px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                   fill="none" stroke="#2b7fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
              </svg>
            </td>
            <td>
              <p style="margin:0;font-size:13px;font-weight:700;color:#2b7fff;">Actief abonnement: Milmap {{ $planLabel }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- CTA --}}
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Stap 1 — Stel je wachtwoord in
  </p>
  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Klik op de knop hieronder om een wachtwoord in te stellen en direct aan de slag te gaan.
    De link is <strong style="color:#f8fafc;">60 minuten</strong> geldig.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="border-radius:8px;background:#2b7fff;">
        <a href="{{ $setupUrl }}"
           style="display:inline-block;height:48px;padding:0 28px;line-height:48px;
                  font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">
          Wachtwoord instellen →
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px;font-size:12px;color:#7e8a9c;word-break:break-all;line-height:1.6;">
    Werkt de knop niet? Kopieer deze URL:<br>
    <span style="color:#2b7fff;">{{ $setupUrl }}</span>
  </p>

  <div style="height:1px;background:#1e293b;margin:0 0 20px;"></div>

  {{-- What's next --}}
  <p style="margin:0 0 10px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Na het instellen van je wachtwoord
  </p>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
    @foreach([
      ['icon' => 'map', 'text' => 'Maak je eerste kaart aan'],
      ['icon' => 'route', 'text' => 'Plan routes met MGRS-coördinaten'],
      ['icon' => 'file-text', 'text' => 'Exporteer PDF routeboeken'],
    ] as $item)
    <tr>
      <td style="padding:6px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="width:28px;vertical-align:top;">
              <div style="width:20px;height:20px;background:#1a2433;border-radius:5px;text-align:center;line-height:20px;">
                <span style="font-size:11px;color:#2b7fff;">✓</span>
              </div>
            </td>
            <td style="vertical-align:middle;padding-left:8px;font-size:13px;color:#cbd5e1;">{{ $item['text'] }}</td>
          </tr>
        </table>
      </td>
    </tr>
    @endforeach
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 20px;"></div>

  <p style="margin:0;font-size:12px;color:#7e8a9c;line-height:1.6;">
    Vragen over je abonnement? Stuur een e-mail naar
    <a href="mailto:app@milmap.nl" style="color:#2b7fff;text-decoration:none;">app@milmap.nl</a>
  </p>

@endsection
