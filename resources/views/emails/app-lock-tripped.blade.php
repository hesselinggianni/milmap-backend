@extends('emails.layout')
@section('title', 'Verdachte activiteit — MilMap app vergrendeld')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#3a1a1a;border:1px solid #5c2424;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#ef4444" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          App-vergrendeling getriggerd
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">10 mislukte PIN-pogingen op uw MilMap app</p>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 14px;font-size:14px;color:#cbd5e1;line-height:1.55;">
    Beste {{ $name }},
  </p>

  <p style="margin:0 0 18px;font-size:14px;color:#cbd5e1;line-height:1.55;">
    We hebben zojuist gedetecteerd dat iemand <strong style="color:#f1f5f9;">10 keer een verkeerde
    PIN-code</strong> heeft ingevoerd op uw MilMap-app. Uit voorzorg is uw app nu één uur
    vergrendeld én zijn <strong style="color:#f1f5f9;">alle actieve sessies (alle apparaten)</strong>
    uitgelogd.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="background:#1a2433;border:1px solid #2a3a52;border-radius:10px;margin-bottom:20px;">
    <tr>
      <td style="padding:14px 16px;font-size:13px;color:#cbd5e1;">
        <strong style="color:#f1f5f9;">Wat is er gebeurd:</strong>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">
          <tr><td style="padding:2px 0;color:#94a3b8;">Tijd</td><td style="padding:2px 0;color:#f1f5f9;text-align:right;">{{ $time }}</td></tr>
          <tr><td style="padding:2px 0;color:#94a3b8;">IP-adres</td><td style="padding:2px 0;color:#f1f5f9;text-align:right;">{{ $ipAddress }}</td></tr>
          <tr><td style="padding:2px 0;color:#94a3b8;">Locatie</td><td style="padding:2px 0;color:#f1f5f9;text-align:right;">{{ $location }}</td></tr>
          <tr><td style="padding:2px 0;color:#94a3b8;">Apparaat</td><td style="padding:2px 0;color:#f1f5f9;text-align:right;">{{ $device }}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <h2 style="margin:0 0 10px;font-size:16px;font-weight:700;color:#f8fafc;">Wat u nu moet doen</h2>

  <ol style="margin:0 0 18px 18px;padding:0;font-size:14px;color:#cbd5e1;line-height:1.6;">
    <li><strong style="color:#f1f5f9;">Wijzig uw wachtwoord direct.</strong> Gebruik een nieuw, sterk
        wachtwoord dat u niet eerder gebruikt heeft.</li>
    <li>Log in op een ander apparaat en controleer of er onbekende sessies of activiteiten zijn.</li>
    <li>Was u dit zelf en is uw PIN vergeten? Geen zorgen — schakel app-vergrendeling uit in
        de instellingen en stel een nieuwe in.</li>
  </ol>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
    <tr>
      <td style="background:#2b7fff;border-radius:8px;">
        <a href="{{ config('app.frontend_url', 'https://app.milmap.nl') }}/account/security"
           style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#fff;
                  text-decoration:none;border-radius:8px;">
          Wachtwoord wijzigen
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.5;">
    Was u dit zelf en is de PIN gewoon vergeten? Dan kunt u deze melding negeren — uw account
    is en blijft veilig. Bij twijfel: wijzig altijd het wachtwoord.
  </p>

@endsection
