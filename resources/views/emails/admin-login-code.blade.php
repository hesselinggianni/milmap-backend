@extends('emails.layout-minimal')
@section('title', __('mail.admin_login_code.subject'))
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">
    {{ __('mail.admin_login_code.title') }}
  </h1>
  <p style="margin:0 0 36px;">{{ __('mail.admin_login_code.intro') }}</p>
  <p style="margin:0 0 36px;font-size:44px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:6px;">{{ $code }}</p>
  <p style="margin:0 0 20px;">{{ __('mail.admin_login_code.expiry', ['minutes' => $expiryMinutes]) }}. {{ __('mail.admin_login_code.expiry_note') }}</p>
  <p style="margin:0;">{{ __('mail.admin_login_code.' . ('admin' === 'admin' ? 'ignore' : 'footer')) }}</p>
@endsection
