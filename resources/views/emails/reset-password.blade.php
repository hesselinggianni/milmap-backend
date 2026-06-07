@extends('emails.layout')
@section('title', 'Wachtwoord resetten — MilMap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#1a2433;border:1px solid #2a3a52;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          Wachtwoord resetten
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">Verzoek ontvangen voor uw account</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Actie vereist
  </p>
  <p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    We hebben een verzoek ontvangen om uw wachtwoord te resetten. Klik op de knop hieronder om een nieuw wachtwoord in te stellen. De link is <strong style="color:#f8fafc;">60 minuten</strong> geldig.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="border-radius:8px;background:#2b7fff;">
        <a href="{{ $resetUrl }}"
           style="display:inline-block;height:44px;padding:0 24px;line-height:44px;
                  font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
          Wachtwoord resetten
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 24px;font-size:12px;color:#7e8a9c;word-break:break-all;line-height:1.6;">
    Werkt de knop niet? Kopieer deze URL:<br>
    <span style="color:#2b7fff;">{{ $resetUrl }}</span>
  </p>

  <div style="height:1px;background:#1e293b;margin:0 0 20px;"></div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="padding:12px 16px;background:#241a06;border:1px solid #5a4413;border-radius:8px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="vertical-align:top;padding-right:10px;padding-top:1px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                   fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
            </td>
            <td style="font-size:13px;color:#fcd34d;line-height:1.5;">
              Heeft u dit niet aangevraagd? Dan kunt u deze e-mail veilig negeren.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

@endsection
