@extends('emails.layout-minimal')
@section('title', __('mail.chat_request.page_title'))
@section('body')
  <h1 style="margin:0 0 16px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.chat_request.title') }}</h1>
  <p style="margin:0 0 32px;">{{ __('mail.chat_request.subtitle', ['name' => $requesterName]) }}</p>
  <p style="margin:0 0 20px;">
    {!! __('mail.chat_request.intro', [
        'name' => '<strong style="color:#01163d;">' . e($requesterName) . '</strong>',
        'accept'  => '<strong style="color:#01163d;">' . __('mail.chat_request.accept') . '</strong>',
        'decline' => '<strong style="color:#01163d;">' . __('mail.chat_request.decline') . '</strong>',
    ]) !!}
  </p>
  <p style="margin:0 0 20px;"><a href="{{ $url }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.chat_request.cta') }}</a></p>
  <p style="margin:0 0 20px;font-size:14px;color:#6b7280;word-break:break-all;">{{ __('mail.chat_request.fallback') }}<br><span style="color:#01163d;">{{ $url }}</span></p>
  <p style="margin:0;font-size:14px;color:#6b7280;">{{ __('mail.chat_request.footer') }}</p>
@endsection
