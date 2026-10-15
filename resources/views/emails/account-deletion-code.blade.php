@extends('emails.layout-minimal')
@section('title', __('mail.account_deletion_code.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.account_deletion_code.title') }}</h1>
  <p style="margin:0 0 36px;">{{ __('mail.account_deletion_code.intro', ['minutes' => $minutes]) }}</p>
  <p style="margin:0 0 36px;font-size:44px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:6px;">{{ $code }}</p>
  <p style="margin:0;">{{ __('mail.account_deletion_code.footer') }}</p>
@endsection
