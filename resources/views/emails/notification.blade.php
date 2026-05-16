@extends('emails.layout')
@section('title', '{{ $emailData["subject"] ?? "Melding" }} — MilMap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#eff5ff;border:1px solid #c3d8ff;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#111827;letter-spacing:-0.01em;line-height:1.2;">
          {{ $emailData['subject'] ?? 'Melding' }}
        </h1>
        <p style="margin:0;font-size:13px;color:#6b7280;">MilMap Platform Notificatie</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#eaecf0;margin:0 0 24px;"></div>

  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#9aa3b0;">
    Bericht
  </p>
  <p style="margin:0;font-size:14px;line-height:1.7;color:#374151;">
    {{ $content }}
  </p>

@endsection
