@extends('emails.layout-minimal')
@section('title', __('mail.partner_payout.title'))
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.partner_payout.title') }}</h1>
  <p style="margin:0 0 24px;">{{ __('mail.partner_payout.intro') }}</p>
  <div style="margin:0 0 28px;padding:20px 18px;background:#f3f4f6;border-radius:8px;text-align:center;">
    <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('mail.partner_payout.amount_label') }}</p>
    <p style="margin:0 0 4px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;">€{{ number_format($amount, 2, ',', '.') }}</p>
    <p style="margin:0;font-size:14px;color:#6b7280;">
      {{ $commissionCount === 1
          ? __('mail.partner_payout.commission_one', ['count' => $commissionCount])
          : __('mail.partner_payout.commission_many', ['count' => $commissionCount]) }}
    </p>
  </div>
  <p style="margin:0;"><a href="{{ rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/') }}/commissies" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.partner_payout.cta') }}</a></p>
@endsection
