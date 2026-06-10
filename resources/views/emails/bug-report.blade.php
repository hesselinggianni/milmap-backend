@extends('emails.layout')
@section('title', 'Bug Report — Milmap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#241011;border:1px solid #4a1f1f;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#dc2626" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          Nieuw Bug Report
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">Ingediend via het Milmap platform</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  <!-- Gebruiker info -->
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Gebruiker
  </p>
  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    <strong style="color:#f8fafc;">{{ $data['user_name'] }}</strong><br>
    <a href="mailto:{{ $data['user_email'] }}" style="color:#2b7fff;text-decoration:none;">{{ $data['user_email'] }}</a>
  </p>

  <!-- Bericht -->
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Bericht
  </p>
  <div style="margin:0 0 24px;padding:14px 16px;background:#0d1320;border:1px solid #1e293b;
              border-radius:8px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {{ $data['message'] }}
  </div>

  <!-- Technische details -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="border:1px solid #1e293b;border-radius:8px;overflow:hidden;margin-bottom:0;">
    <tr>
      <td colspan="2"
          style="padding:10px 16px;background:#0d1320;border-bottom:1px solid #1e293b;
                 font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
        Technische details
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;width:30%;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">
        URL
      </td>
      <td style="padding:11px 16px;font-size:12px;color:#2b7fff;border-bottom:1px solid #1a2433;word-break:break-all;">
        {{ $data['url'] ?? 'Niet opgegeven' }}
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">
        User Agent
      </td>
      <td style="padding:11px 16px;font-size:11.5px;color:#cbd5e1;border-bottom:1px solid #1a2433;font-family:monospace;word-break:break-all;">
        {{ $data['user_agent'] ?? 'Niet opgegeven' }}
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;font-weight:500;">
        Tijdstip
      </td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;">
        {{ $data['timestamp'] }}
      </td>
    </tr>
  </table>

@endsection
