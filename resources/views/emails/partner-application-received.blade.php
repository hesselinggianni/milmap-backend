@extends('emails.layout-minimal')
@section('title', __('mail.partner_application_received.subject'))
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.partner_application_received.title') }}</h1>
  <p style="margin:0 0 20px;">
    {!! __('mail.partner_application_received.intro', [
        'for_company' => $partner->company_name
            ? __('mail.partner_application_received.for_company', [
                'company' => '<strong style="color:#01163d;">' . e($partner->company_name) . '</strong>',
              ])
            : '',
    ]) !!}
  </p>
  <p style="margin:0 0 20px;">
    {!! __('mail.partner_application_received.referral', [
        'code' => '<strong style="color:#01163d;">' . e($partner->referral_code) . '</strong>',
    ]) !!}
  </p>
  @if($setupUrl)
  <p style="margin:0 0 20px;">{{ __('mail.partner_application_received.setup_intro') }}</p>
  <p style="margin:0 0 28px;"><a href="{{ $setupUrl }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.partner_application_received.setup_cta') }}</a></p>
  @endif
  <p style="margin:0;font-size:14px;color:#6b7280;">
    {{ __('mail.partner_application_received.contact_before') }}
    <a href="https://milmap.nl/#contact" style="color:#01163d;font-weight:700;">{{ __('mail.partner_application_received.contact_link') }}</a>
    {{ __('mail.partner_application_received.contact_after') }}
  </p>
@endsection
