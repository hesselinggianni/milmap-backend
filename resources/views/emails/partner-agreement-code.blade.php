@extends('emails.layout-minimal')
@section('title', __('mail.partner_agreement_code.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.partner_agreement_code.title') }}</h1>
  <p style="margin:0 0 36px;">{{ __('mail.partner_agreement_code.intro') }}</p>
  <p style="margin:0 0 8px;font-weight:700;color:#01163d;">{{ __('mail.partner_agreement_code.code_label') }}</p>
  <p style="margin:0 0 8px;font-size:44px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:6px;">{{ $code }}</p>
  <p style="margin:0 0 36px;font-size:14px;color:#6b7280;">{{ __('mail.partner_agreement_code.code_validity') }}</p>
  <p style="margin:0 0 20px;">{{ __('mail.partner_agreement_code.cta_intro') }}</p>
  <p style="margin:0 0 32px;"><a href="{{ $confirmUrl }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.partner_agreement_code.cta') }}</a></p>
  <p style="margin:0;">{{ __('mail.partner_agreement_code.footer_before') }} <a href="https://milmap.nl/#contact" style="color:#01163d;">{{ __('mail.partner_agreement_code.footer_link') }}</a> {{ __('mail.partner_agreement_code.footer_after') }}</p>
@endsection
