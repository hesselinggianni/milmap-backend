@extends('emails.layout')
@section('title', 'Nieuw Contact Ticket — MilMap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#eff6ff;border:1px solid #bfdbfe;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#111827;letter-spacing:-0.01em;line-height:1.2;">
          Nieuw Contact Ticket #{{ $ticket->id }}
        </h1>
        <p style="margin:0;font-size:13px;color:#6b7280;">Ingediend via milmap.nl</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#eaecf0;margin:0 0 24px;"></div>

  <!-- Type badge -->
  <p style="margin:0 0 16px;">
    <span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:#eff6ff;color:#1d4ed8;">
      {{ ucfirst($ticket->type) }}
    </span>
  </p>

  <!-- Onderwerp -->
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#9aa3b0;">
    Onderwerp
  </p>
  <p style="margin:0 0 20px;font-size:16px;font-weight:700;color:#111827;">
    {{ $ticket->subject }}
  </p>

  <!-- Afzender -->
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#9aa3b0;">
    Van
  </p>
  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#374151;">
    <strong style="color:#111827;">{{ $ticket->name }}</strong><br>
    <a href="mailto:{{ $ticket->email }}" style="color:#2b7fff;text-decoration:none;">{{ $ticket->email }}</a>
  </p>

  <!-- Bericht -->
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#9aa3b0;">
    Bericht
  </p>
  <div style="margin:0 0 24px;padding:14px 16px;background:#f8f9fb;border:1px solid #eaecf0;
              border-radius:8px;font-size:14px;line-height:1.7;color:#374151;white-space:pre-line;">
{{ $ticket->message }}
  </div>

  <!-- Meta -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="border:1px solid #eaecf0;border-radius:8px;overflow:hidden;">
    <tr>
      <td colspan="2" style="padding:10px 16px;background:#f8f9fb;border-bottom:1px solid #eaecf0;
                 font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#9aa3b0;">
        Ticket details
      </td>
    </tr>
    <tr>
      <td style="padding:11px 16px;width:30%;font-size:12.5px;color:#6b7280;border-bottom:1px solid #f3f4f6;font-weight:500;">Ticket ID</td>
      <td style="padding:11px 16px;font-size:13px;color:#111827;border-bottom:1px solid #f3f4f6;font-weight:700;">#{{ $ticket->id }}</td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#6b7280;border-bottom:1px solid #f3f4f6;font-weight:500;">Status</td>
      <td style="padding:11px 16px;font-size:13px;color:#16a34a;border-bottom:1px solid #f3f4f6;font-weight:600;">Open</td>
    </tr>
    <tr>
      <td style="padding:11px 16px;font-size:12.5px;color:#6b7280;font-weight:500;">Ontvangen</td>
      <td style="padding:11px 16px;font-size:13px;color:#111827;">{{ $ticket->created_at->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s') }}</td>
    </tr>
  </table>

@endsection
