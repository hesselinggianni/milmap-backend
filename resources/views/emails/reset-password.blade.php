@extends('emails.layout-minimal')
@section('title', __('mail.reset_password.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.reset_password.title') }}</h1>
  <p style="margin:0 0 20px;">{!! __('mail.reset_password.intro', ['duration' => '<strong style="color:#01163d;">' . __('mail.reset_password.duration') . '</strong>']) !!}</p>
  <p style="margin:0 0 20px;"><a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.reset_password.cta') }}</a></p>
  <p style="margin:0 0 32px;font-size:14px;color:#6b7280;word-break:break-all;">{{ __('mail.reset_password.fallback') }}<br><span style="color:#01163d;">{{ $resetUrl }}</span></p>
  <p style="margin:0;">{{ __('mail.reset_password.warning') }}</p>
@endsection
