@extends('emails.layout')
@section('title', 'Wachtwoord gewijzigd — MilMap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#f0fdf4;border:1px solid #bbf7d0;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#16a34a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#111827;letter-spacing:-0.01em;line-height:1.2;">
          Wachtwoord gewijzigd
        </h1>
        <p style="margin:0;font-size:13px;color:#6b7280;">Uw account is bijgewerkt</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#eaecf0;margin:0 0 24px;"></div>

  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#9aa3b0;">
    Bevestiging
  </p>
  <p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#374151;">
    Hallo <strong style="color:#111827;">{{ $name }}</strong>,<br><br>
    Uw wachtwoord is succesvol gewijzigd. U kunt nu inloggen met uw nieuwe wachtwoord.
  </p>

  <div style="height:1px;background:#eaecf0;margin:0 0 20px;"></div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="vertical-align:top;padding-right:10px;padding-top:1px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                   fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
            </td>
            <td style="font-size:13px;color:#92400e;line-height:1.5;">
              Heeft u dit niet zelf gedaan? Neem dan onmiddellijk contact op met uw beheerder.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

@endsection
