@extends('emails.layout')
@section('title', 'Nieuwe gebruiker — Milmap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#1a2433;border:1px solid #2a3a52;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
            <path d="M19 8v6"/><path d="M22 11h-6"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          Nieuwe gebruiker
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">Geregistreerd op Milmap</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="border:1px solid #1e293b;border-radius:8px;overflow:hidden;">
    <tr>
      <td colspan="2" style="padding:10px 16px;background:#0d1320;border-bottom:1px solid #1e293b;
                 font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
        Accountgegevens
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;width:30%;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">E-mail</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;border-bottom:1px solid #1a2433;">{{ $user->email }}</td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">ID</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;border-bottom:1px solid #1a2433;font-family:monospace;">{{ $user->id }}</td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">Datum</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;border-bottom:1px solid #1a2433;">{{ now() }}</td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;{{ !empty($referrer) ? 'border-bottom:1px solid #1a2433;' : '' }}font-weight:500;">Geregistreerd via</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;{{ !empty($referrer) ? 'border-bottom:1px solid #1a2433;' : '' }}word-break:break-all;">{{ !empty($sourceUrl) ? $sourceUrl : 'Onbekend' }}</td>
    </tr>
    @if(!empty($referrer))
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;font-weight:500;">Herkomst</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;word-break:break-all;">{{ $referrer }}</td>
    </tr>
    @endif
  </table>

@endsection
