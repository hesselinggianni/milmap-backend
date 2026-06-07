@extends('emails.layout')
@section('title', 'Feature Request — MilMap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#1a2433;border:1px solid #2a3a52;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          Nieuwe Feature Request
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">Ingediend via milmap.nl</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  <!-- Titel -->
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Functionaliteit
  </p>
  <div style="margin:0 0 24px;padding:14px 16px;background:#1a2433;border:1px solid #2a3a52;
              border-radius:8px;">
    <p style="margin:0;font-size:16px;font-weight:700;color:#fcd34d;line-height:1.4;">
      {{ $data['title'] }}
    </p>
    @if(!empty($data['category']))
    <p style="margin:6px 0 0;font-size:12px;color:#2b7fff;">
      Categorie: {{ $data['category'] }}
    </p>
    @endif
  </div>

  <!-- Gebruiker -->
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Ingediend door
  </p>
  <p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    <strong style="color:#f8fafc;">{{ $data['user_name'] }}</strong><br>
    <a href="mailto:{{ $data['user_email'] }}" style="color:#2b7fff;text-decoration:none;">{{ $data['user_email'] }}</a>
  </p>

  <!-- Beschrijving -->
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Omschrijving
  </p>
  <div style="margin:0 0 24px;padding:14px 16px;background:#0d1320;border:1px solid #1e293b;
              border-radius:8px;font-size:14px;line-height:1.7;color:#cbd5e1;white-space:pre-line;">
{{ $data['description'] }}
  </div>

  <!-- Meta -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="border:1px solid #1e293b;border-radius:8px;overflow:hidden;">
    <tr>
      <td colspan="2"
          style="padding:10px 16px;background:#0d1320;border-bottom:1px solid #1e293b;
                 font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
        Details
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;width:30%;font-size:12.5px;color:#94a3b8;border-bottom:1px solid #1a2433;font-weight:500;">
        Prioriteit
      </td>
      <td style="padding:11px 16px;font-size:13px;border-bottom:1px solid #1a2433;">
        @if($data['priority'] === 'high')
          <span style="color:#dc2626;font-weight:600;">⬆ Hoog</span>
        @elseif($data['priority'] === 'medium')
          <span style="color:#2b7fff;font-weight:600;">→ Gemiddeld</span>
        @else
          <span style="color:#94a3b8;font-weight:600;">⬇ Laag</span>
        @endif
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
