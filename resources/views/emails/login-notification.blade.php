@extends('emails.layout-minimal')
@section('title', __('mail.login_notification.subject'))
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">
    {{ __('mail.login_notification.title') }}
  </h1>
  <p style="margin:0 0 20px;">{{ __('mail.login_notification.greeting', ['name' => $name]) }}</p>
  <p style="margin:0 0 20px;">{{ __('mail.login_notification.intro') }}</p>
  <p style="margin:0 0 28px;line-height:1.9;">
    <strong style="color:#01163d;">{{ __('mail.login_notification.time') }}:</strong> {{ $loginTime }}<br>
    <strong style="color:#01163d;">{{ __('mail.login_notification.ip') }}:</strong> {{ $ipAddress }}<br>
    <strong style="color:#01163d;">{{ __('mail.login_notification.location') }}:</strong> {{ $location }}<br>
    <strong style="color:#01163d;">{{ __('mail.login_notification.device') }}:</strong> {{ $device }}
  </p>
  <p style="margin:0;">{{ __('mail.login_notification.warning') }}</p>
@endsection
