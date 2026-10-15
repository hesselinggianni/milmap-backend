@extends('emails.layout-minimal')
@section('title', __('mail.bug_report.page_title'))
@section('body')
  <h1 style="margin:0 0 12px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">
    {{ __('mail.bug_report.title') }}
  </h1>
  <p style="margin:0 0 32px;">{{ __('mail.bug_report.subtitle') }}</p>

  <p style="margin:0 0 28px;line-height:1.9;">
    <strong style="color:#01163d;">{{ __('mail.bug_report.user') }}:</strong>
    {{ $data['user_name'] }}
    (<a href="mailto:{{ $data['user_email'] }}" style="color:#01163d;">{{ $data['user_email'] }}</a>)
  </p>

  <p style="margin:0 0 8px;"><strong style="color:#01163d;">{{ __('mail.bug_report.message') }}:</strong></p>
  <div style="margin:0 0 28px;padding:16px 18px;background:#f3f4f6;border-radius:8px;font-size:16px;line-height:1.7;color:#01163d;white-space:pre-wrap;">{{ $data['message'] }}</div>

  <p style="margin:0 0 8px;"><strong style="color:#01163d;">{{ __('mail.bug_report.tech_title') }}</strong></p>
  <p style="margin:0;line-height:1.9;font-size:15px;">
    <strong style="color:#01163d;">{{ __('mail.bug_report.url') }}:</strong>
    <span style="word-break:break-all;">{{ $data['url'] ?? __('mail.bug_report.not_provided') }}</span><br>
    <strong style="color:#01163d;">{{ __('mail.bug_report.user_agent') }}:</strong>
    <span style="word-break:break-all;">{{ $data['user_agent'] ?? __('mail.bug_report.not_provided') }}</span><br>
    <strong style="color:#01163d;">{{ __('mail.bug_report.time') }}:</strong> {{ $data['timestamp'] }}
  </p>
@endsection
