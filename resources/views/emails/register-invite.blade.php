@extends('emails.layout-minimal')
@section('title', __('mail.register_invite.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 16px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.register_invite.title') }}</h1>
  <p style="margin:0 0 32px;">{{ __('mail.register_invite.subtitle') }}</p>
  <p style="margin:0 0 20px;">
    {!! __('mail.register_invite.intro', [
        'workspace' => '<strong style="color:#01163d;">' . e($workspace->name) . '</strong>',
    ]) !!}
  </p>
  <p style="margin:0 0 20px;"><a href="{{ route('workspace.invite.accept', ['id' => $invitation->id]) }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.register_invite.cta') }}</a></p>
  <p style="margin:0;font-size:14px;color:#6b7280;">{{ __('mail.register_invite.footer') }}</p>
@endsection
