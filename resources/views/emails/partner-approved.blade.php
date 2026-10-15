@extends('emails.layout-minimal')
@section('title', __('mail.partner_approved.title'))
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.partner_approved.title') }}</h1>
  @php
    $pct = fn ($v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    $bold = fn ($s) => '<strong style="color:#01163d;">' . $s . '</strong>';
  @endphp
  <p style="margin:0 0 20px;">
    {!! __('mail.partner_approved.intro', [
        'discount'   => $bold(__('mail.partner_approved.discount', ['rate' => $pct($partner->discount_rate)])),
        'commission' => $bold(__('mail.partner_approved.commission', ['rate' => $pct($partner->commission_rate)])),
    ]) !!}
  </p>
  <div style="margin:0 0 28px;padding:16px 18px;background:#f3f4f6;border-radius:8px;">
    <p style="margin:0 0 6px;font-size:14px;color:#6b7280;">{{ __('mail.partner_approved.step_label') }}</p>
    <p style="margin:0;font-size:16px;line-height:1.6;color:#01163d;">
      {!! __('mail.partner_approved.step_body', [
          'agreement' => '<strong>' . __('mail.partner_approved.agreement') . '</strong>',
          'code'      => '<strong>' . e($partner->referral_code) . '</strong>',
      ]) !!}
    </p>
  </div>
  <p style="margin:0 0 8px;"><strong style="color:#01163d;">{{ __('mail.partner_approved.next_title') }}</strong></p>
  <ol style="margin:0 0 28px;padding-left:22px;">
    <li style="margin:6px 0;">{{ __('mail.partner_approved.next_1') }}</li>
    <li style="margin:6px 0;">{{ __('mail.partner_approved.next_2') }}</li>
    <li style="margin:6px 0;">{{ __('mail.partner_approved.next_3') }}</li>
  </ol>
  <p style="margin:0 0 28px;"><a href="{{ rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/') }}/dashboard" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.partner_approved.cta') }}</a></p>
  <p style="margin:0;font-size:13px;color:#6b7280;">{{ __('mail.partner_approved.vat_note') }}</p>
@endsection
