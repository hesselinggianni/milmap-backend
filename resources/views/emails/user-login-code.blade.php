@extends('emails.layout')

@section('title', __('mail.user_login_code.subject'))

@section('body')
  <h2 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#f8fafc;">
    {{ __('mail.user_login_code.title') }}
  </h2>
  <p style="margin:0 0 24px;font-size:14px;color:#cbd5e1;line-height:1.6;">
    {{ __('mail.user_login_code.intro') }}
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 24px;">
    <tr>
      <td align="center"
          style="background:linear-gradient(135deg,#2b7fff 0%,#1a6ae6 100%);
                 border-radius:10px;padding:28px 20px;">
        <span style="font-size:42px;font-weight:700;color:#ffffff;letter-spacing:10px;
                     font-family:'Courier New',monospace;">
          {{ $code }}
        </span>
      </td>
    </tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 20px;">
    <tr>
      <td style="background-color:#1a2433;border-left:4px solid #2b7fff;border-radius:4px;padding:14px 16px;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#f8fafc;">
          {{ __('mail.user_login_code.expiry', ['minutes' => $expiryMinutes]) }}
        </p>
        <p style="margin:0;font-size:13px;color:#cbd5e1;line-height:1.5;">
          {{ __('mail.user_login_code.expiry_note') }}
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:13px;color:#7e8a9c;line-height:1.6;">
    {{ __('mail.user_login_code.footer') }}
  </p>
@endsection
