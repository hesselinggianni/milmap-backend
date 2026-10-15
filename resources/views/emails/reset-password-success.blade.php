@extends('emails.layout-minimal')
@section('title', __('mail.reset_password_success.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.reset_password_success.title') }}</h1>
  <p style="margin:0 0 20px;">{!! __('mail.reset_password_success.body', ['name' => '<strong style="color:#01163d;">' . e($name) . '</strong>']) !!}</p>
  <p style="margin:0;">{{ __('mail.reset_password_success.warning') }}</p>
@endsection
