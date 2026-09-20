@extends('emails.layout')
@section('title', __('mail.partner_approved.title'))
@section('body')

  <h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;">
    {{ __('mail.partner_approved.title') }}
  </h1>

  @php
    $pct = fn ($v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    $bold = fn ($s) => '<strong style="color:#f8fafc;">' . $s . '</strong>';
  @endphp

  <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {!! __('mail.partner_approved.intro', [
        'discount'   => $bold(__('mail.partner_approved.discount', ['rate' => $pct($partner->discount_rate)])),
        'commission' => $bold(__('mail.partner_approved.commission', ['rate' => $pct($partner->commission_rate)])),
    ]) !!}
  </p>

  {{-- Overeenkomst eerst --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 20px;background:#1a2433;border:1px solid #2a3a52;border-radius:10px;">
    <tr><td style="padding:16px 18px;">
      <p style="margin:0 0 6px;font-size:13px;color:#94a3b8;">{{ __('mail.partner_approved.step_label') }}</p>
      <p style="margin:0;font-size:14px;line-height:1.6;color:#f8fafc;">
        {!! __('mail.partner_approved.step_body', [
            'agreement' => '<strong>' . __('mail.partner_approved.agreement') . '</strong>',
            'code'      => '<strong style="color:#2b7fff;">' . e($partner->referral_code) . '</strong>',
        ]) !!}
      </p>
    </td></tr>
  </table>

  <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {{ __('mail.partner_approved.next_title') }}
  </p>
  <ol style="margin:0 0 20px;padding-left:20px;font-size:14px;line-height:1.9;color:#cbd5e1;">
    <li>{{ __('mail.partner_approved.next_1') }}</li>
    <li>{{ __('mail.partner_approved.next_2') }}</li>
    <li>{{ __('mail.partner_approved.next_3') }}</li>
  </ol>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
    <tr>
      <td style="border-radius:10px;background:#2b7fff;">
        <a href="{{ rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/') }}/dashboard"
           style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
          {{ __('mail.partner_approved.cta') }}
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:12px;line-height:1.7;color:#64748b;">
    {{ __('mail.partner_approved.vat_note') }}
  </p>

@endsection
