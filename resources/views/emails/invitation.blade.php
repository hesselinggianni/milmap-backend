@extends('emails.layout-minimal')
@section('title', __('mail.invitation.page_title'))
@section('body')
  <h1 style="margin:0 0 16px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.invitation.title') }}</h1>
  <p style="margin:0 0 32px;">{{ __('mail.invitation.subtitle', ['name' => $inviterName]) }}</p>
  <p style="margin:0 0 20px;">
    {!! __('mail.invitation.intro', [
        'name'     => '<strong style="color:#01163d;">' . e($inviterName) . '</strong>',
        'resource' => e($resourceLabel),
        'title'    => '<strong style="color:#01163d;">' . e($resourceTitle) . '</strong>',
    ]) !!}
  </p>
  <p style="margin:0 0 20px;"><a href="{{ $acceptUrl }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.invitation.cta') }}</a></p>
  @if($isNewUser)
    <p style="margin:0 0 20px;padding:14px 18px;background:#f3f4f6;border-radius:8px;font-size:15px;color:#01163d;">
      {!! __('mail.invitation.new_user', [
          'free' => '<strong>' . __('mail.invitation.new_user_free') . '</strong>',
      ]) !!}
    </p>
  @endif
  <p style="margin:0 0 20px;font-size:14px;color:#6b7280;word-break:break-all;">{{ __('mail.invitation.fallback') }}<br><span style="color:#01163d;">{{ $acceptUrl }}</span></p>
  <p style="margin:0;font-size:14px;color:#6b7280;">{{ __('mail.invitation.footer') }}</p>
@endsection
