@extends('emails.layout-minimal')
@section('title', __('mail.collaboration_invite.title'))
@section('body')
  <h1 style="margin:0 0 16px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.collaboration_invite.title') }}</h1>
  <p style="margin:0 0 28px;">
    {!! __('mail.collaboration_invite.intro', [
        'name' => e($inviter->first_name),
        'map'  => '<strong style="color:#01163d;">' . e($map->title) . '</strong>',
    ]) !!}
  </p>
  <p style="margin:0 0 8px;"><strong style="color:#01163d;">{{ __('mail.collaboration_invite.what_title') }}</strong></p>
  <ul style="margin:0 0 28px;padding-left:20px;">
    <li style="margin:6px 0;">{{ __('mail.collaboration_invite.what_1') }}</li>
    <li style="margin:6px 0;">{{ __('mail.collaboration_invite.what_2') }}</li>
    <li style="margin:6px 0;">{{ __('mail.collaboration_invite.what_3') }}</li>
    <li style="margin:6px 0;">{{ __('mail.collaboration_invite.what_4') }}</li>
  </ul>
  <p style="margin:0 0 28px;"><a href="{{ $acceptLink }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.collaboration_invite.cta') }}</a></p>
  <p style="margin:0 0 20px;padding:14px 18px;background:#f3f4f6;border-radius:8px;font-size:15px;color:#01163d;">
    <strong>{{ __('mail.collaboration_invite.privacy_label') }}</strong> {{ __('mail.collaboration_invite.privacy_body') }}
  </p>
  <p style="margin:0;font-size:14px;color:#6b7280;">{{ __('mail.collaboration_invite.questions', ['email' => $inviter->email]) }}</p>
  @if(!$acceptLink)
    <p style="margin:12px 0 0;font-size:14px;color:#6b7280;word-break:break-all;">
      {{ __('mail.collaboration_invite.copy_link') }}<br>{{ $acceptLink }}
    </p>
  @endif
@endsection
