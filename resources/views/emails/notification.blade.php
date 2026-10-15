@extends('emails.layout-minimal')
@section('title', ($emailData['subject'] ?? __('mail.notification.fallback_subject')) . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 12px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ $emailData['subject'] ?? __('mail.notification.fallback_subject') }}</h1>
  <p style="margin:0 0 32px;">{{ __('mail.notification.subtitle') }}</p>
  <p style="margin:0;">{{ $content }}</p>
@endsection
