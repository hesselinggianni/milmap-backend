@extends('emails.layout')
@section('title', __('mail.account_closed.title') . ' — Milmap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#1a2433;border:1px solid #334155;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          {{ __('mail.account_closed.title') }}
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">{{ __('mail.account_closed.subtitle') }}</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    {{ __('mail.account_closed.eyebrow') }}
  </p>
  <p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {!! __('mail.account_closed.greeting', [
        'name' => '<strong style="color:#f8fafc;">' . e($name) . '</strong>',
    ]) !!}<br><br>
    {{ __('mail.account_closed.intro') }}
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
    <tr>
      <td style="padding:14px 16px;background:#111c2e;border:1px solid #1e293b;border-radius:8px;">
        <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
          {{ __('mail.account_closed.sub_label') }}
        </p>
        <p style="margin:0;font-size:13px;line-height:1.6;color:#cbd5e1;">
          {{ $hadSubscription ? __('mail.account_closed.sub_yes') : __('mail.account_closed.sub_no') }}
        </p>
      </td>
    </tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
    <tr>
      <td style="padding:14px 16px;background:#111c2e;border:1px solid #1e293b;border-radius:8px;">
        <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
          {{ __('mail.account_closed.data_label') }}
        </p>
        <p style="margin:0;font-size:13px;line-height:1.6;color:#cbd5e1;">
          {{ __('mail.account_closed.data_body') }}
        </p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 20px;"></div>

  <p style="margin:0;font-size:13px;line-height:1.7;color:#94a3b8;">
    {{ __('mail.account_closed.closing') }}
  </p>

@endsection
