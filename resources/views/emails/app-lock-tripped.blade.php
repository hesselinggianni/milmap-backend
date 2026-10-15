@extends('emails.layout-minimal')
@section('title', __('mail.app_lock_tripped.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.app_lock_tripped.title') }}</h1>
  <p style="margin:0 0 20px;">{{ __('mail.app_lock_tripped.greeting', ['name' => $name]) }}</p>
  <p style="margin:0 0 20px;">{!! __('mail.app_lock_tripped.intro', ['wrong' => '<strong style="color:#01163d;">' . __('mail.app_lock_tripped.wrong_pin') . '</strong>', 'sessions' => '<strong style="color:#01163d;">' . __('mail.app_lock_tripped.all_sessions') . '</strong>']) !!}</p>
  <p style="margin:0 0 8px;font-weight:700;color:#01163d;">{{ __('mail.app_lock_tripped.what_happened') }}</p>
  <p style="margin:0 0 28px;line-height:1.9;">
    <strong style="color:#01163d;">{{ __('mail.app_lock_tripped.time') }}:</strong> {{ $time }}<br>
    <strong style="color:#01163d;">{{ __('mail.app_lock_tripped.ip') }}:</strong> {{ $ipAddress }}<br>
    <strong style="color:#01163d;">{{ __('mail.app_lock_tripped.location') }}:</strong> {{ $location }}<br>
    <strong style="color:#01163d;">{{ __('mail.app_lock_tripped.device') }}:</strong> {{ $device }}
  </p>
  <p style="margin:0 0 8px;font-weight:700;color:#01163d;">{{ __('mail.app_lock_tripped.todo_title') }}</p>
  <ol style="margin:0 0 24px 20px;padding:0;line-height:1.7;">
    <li><strong style="color:#01163d;">{{ __('mail.app_lock_tripped.todo_1_strong') }}</strong> {{ __('mail.app_lock_tripped.todo_1') }}</li>
    <li>{{ __('mail.app_lock_tripped.todo_2') }}</li>
    <li>{{ __('mail.app_lock_tripped.todo_3') }}</li>
  </ol>
  <p style="margin:0 0 28px;"><a href="{{ config('app.frontend_url', 'https://app.milmap.nl') }}/account/security" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.app_lock_tripped.cta') }}</a></p>
  <p style="margin:0;">{{ __('mail.app_lock_tripped.footer') }}</p>
@endsection
