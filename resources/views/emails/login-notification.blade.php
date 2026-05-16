@extends('emails.layout')
@section('title', 'Nieuwe inlog gedetecteerd — MilMap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#eff5ff;border:1px solid #c3d8ff;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
            <polyline points="10 17 15 12 10 7"/>
            <line x1="15" y1="12" x2="3" y2="12"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#111827;letter-spacing:-0.01em;line-height:1.2;">
          Nieuwe inlog gedetecteerd
        </h1>
        <p style="margin:0;font-size:13px;color:#6b7280;">Er is ingelogd op uw MilMap account</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#eaecf0;margin:0 0 24px;"></div>

  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#9aa3b0;">
    Account
  </p>
  <p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#374151;">
    Hallo <strong style="color:#111827;">{{ $name }}</strong>, er is zojuist ingelogd op uw account met de onderstaande gegevens.
  </p>

  <!-- Inloggegevens tabel -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="border:1px solid #eaecf0;border-radius:8px;overflow:hidden;margin-bottom:24px;">
    <tr>
      <td colspan="2"
          style="padding:10px 16px;background:#f8f9fb;border-bottom:1px solid #eaecf0;
                 font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#9aa3b0;">
        Inloggegevens
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;width:38%;font-size:12.5px;color:#6b7280;border-bottom:1px solid #f3f4f6;font-weight:500;">
        Tijdstip
      </td>
      <td style="padding:11px 16px;font-size:13px;color:#111827;border-bottom:1px solid #f3f4f6;">
        {{ $loginTime }}
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#6b7280;border-bottom:1px solid #f3f4f6;font-weight:500;">
        IP-adres
      </td>
      <td style="padding:11px 16px;font-size:13px;color:#111827;border-bottom:1px solid #f3f4f6;font-family:monospace;">
        {{ $ipAddress }}
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#6b7280;border-bottom:1px solid #f3f4f6;font-weight:500;">
        Locatie
      </td>
      <td style="padding:11px 16px;font-size:13px;color:#111827;border-bottom:1px solid #f3f4f6;">
        {{ $location }}
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#6b7280;font-weight:500;">
        Apparaat
      </td>
      <td style="padding:11px 16px;font-size:13px;color:#111827;">
        {{ $device }}
      </td>
    </tr>
  </table>

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
              Herkent u deze inlog niet? Wijzig dan onmiddellijk uw wachtwoord en neem contact op met uw beheerder.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

@endsection
