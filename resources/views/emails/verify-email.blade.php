@extends('emails.layout-minimal')
@section('title', __('mail.verify_email.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.verify_email.title') }}</h1>
  @if($firstName)<p style="margin:0 0 20px;">{{ __('mail.verify_email.greeting', ['name' => $firstName]) }}</p>@endif
  <p style="margin:0 0 20px;">{!! __('mail.verify_email.intro', ['brand' => '<strong style="color:#01163d;">'.'Milmap</strong>', 'duration' => '<strong style="color:#01163d;">' . __('mail.verify_email.duration') . '</strong>']) !!}</p>
  <p style="margin:0 0 20px;"><a href="{{ $verificationUrl }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.verify_email.cta') }}</a></p>
  <p style="margin:0 0 20px;font-size:14px;color:#6b7280;word-break:break-all;">{{ __('mail.verify_email.fallback') }}<br><span style="color:#01163d;">{{ $verificationUrl }}</span></p>
  @if($setPasswordUrl)
  <p style="margin:32px 0 8px;font-weight:700;color:#01163d;">{{ __('mail.verify_email.password_title') }}</p>
  <p style="margin:0 0 20px;">{{ __('mail.verify_email.password_body') }}</p>
  <p style="margin:0 0 20px;"><a href="{{ $setPasswordUrl }}" style="color:#01163d;font-weight:700;">{{ __('mail.verify_email.password_cta') }}</a></p>
  @endif
  <p style="margin:32px 0 20px;">{{ __('mail.verify_email.grace') }}</p>
  <p style="margin:0;">{{ __('mail.verify_email.footer') }}</p>
@endsection
