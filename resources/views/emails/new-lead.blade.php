@extends('emails.layout')
@section('title', 'Nieuwe lead — Milmap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#1a2433;border:1px solid #2a3a52;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <path d="M4 4h16v16H4z" opacity="0"/><path d="M22 6 12 13 2 6"/>
            <path d="M2 6h20v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          Nieuwe lead
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">Ingevuld op Milmap</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="border:1px solid #1e293b;border-radius:8px;overflow:hidden;">
    <tr>
      <td colspan="2" style="padding:10px 16px;background:#0d1320;border-bottom:1px solid #1e293b;
                 font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
        Leadgegevens
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;width:30%;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">E-mail</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;border-bottom:1px solid #1a2433;">{{ $lead->email }}</td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">ID</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;border-bottom:1px solid #1a2433;font-family:monospace;">{{ $lead->id }}</td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">Bron</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;border-bottom:1px solid #1a2433;">{{ $lead->source ?: 'Onbekend' }}</td>
    </tr>
    @if(!empty($lead->utm_source))
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">UTM-bron</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;border-bottom:1px solid #1a2433;word-break:break-all;">{{ $lead->utm_source }}</td>
    </tr>
    @endif
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#94a3b8;font-weight:500;">Datum</td>
      <td style="padding:11px 16px;font-size:13px;color:#f8fafc;">{{ optional($lead->created_at)->format('d-m-Y H:i') ?? now()->format('d-m-Y H:i') }}</td>
    </tr>
  </table>

@endsection
