@extends('emails.layout-minimal')
@section('title', __('mail.account_closed.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.account_closed.title') }}</h1>
  <p style="margin:0 0 20px;">{!! __('mail.account_closed.greeting', ['name' => '<strong style="color:#01163d;">' . e($name) . '</strong>']) !!}</p>
  <p style="margin:0 0 20px;">{{ __('mail.account_closed.intro') }}</p>
  <p style="margin:0 0 20px;"><strong style="color:#01163d;">{{ __('mail.account_closed.sub_label') }}:</strong> {{ $hadSubscription ? __('mail.account_closed.sub_yes') : __('mail.account_closed.sub_no') }}</p>
  <p style="margin:0 0 32px;"><strong style="color:#01163d;">{{ __('mail.account_closed.data_label') }}:</strong> {{ __('mail.account_closed.data_body') }}</p>
  <p style="margin:0;">{{ __('mail.account_closed.closing') }}</p>
@endsection
