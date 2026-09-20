@extends('emails.layout')

@section('title', __('mail.collaboration_invite.title'))

@section('body')
<div style="padding: 24px 0; text-align: center;">
    <h2 style="color: #f8fafc; margin: 0 0 16px 0; font-size: 24px; font-weight: bold;">
        {{ __('mail.collaboration_invite.title') }}
    </h2>
    <p style="color: #cbd5e1; margin: 0 0 24px 0; font-size: 16px;">
        {!! __('mail.collaboration_invite.intro', [
            'name' => e($inviter->first_name),
            'map'  => '<strong>' . e($map->title) . '</strong>',
        ]) !!}
    </p>
</div>

<div style="background: #f5f5f5; padding: 24px; border-radius: 8px; margin: 24px 0;">
    <h3 style="color: #f8fafc; margin: 0 0 12px 0;">{{ __('mail.collaboration_invite.what_title') }}</h3>
    <ul style="color: #cbd5e1; margin: 0; padding-left: 20px;">
        <li style="margin: 8px 0;">{{ __('mail.collaboration_invite.what_1') }}</li>
        <li style="margin: 8px 0;">{{ __('mail.collaboration_invite.what_2') }}</li>
        <li style="margin: 8px 0;">{{ __('mail.collaboration_invite.what_3') }}</li>
        <li style="margin: 8px 0;">{{ __('mail.collaboration_invite.what_4') }}</li>
    </ul>
</div>

<div style="margin: 32px 0; text-align: center;">
    <a href="{{ $acceptLink }}" style="display: inline-block; padding: 12px 32px; background: #2b7fff; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
        {{ __('mail.collaboration_invite.cta') }}
    </a>
</div>

<div style="background: #1a2433; padding: 16px; border-left: 4px solid #2b7fff; border-radius: 4px; margin: 24px 0;">
    <p style="color: #cbd5e1; margin: 0; font-size: 14px;">
        <strong>{{ __('mail.collaboration_invite.privacy_label') }}</strong> {{ __('mail.collaboration_invite.privacy_body') }}
    </p>
</div>

<hr style="border: none; border-top: 1px solid #1e293b; margin: 24px 0;">

<p style="color: #7e8a9c; font-size: 12px; margin: 0;">
    {{ __('mail.collaboration_invite.questions', ['email' => $inviter->email]) }}
</p>

@if(!$acceptLink)
    <p style="color: #7e8a9c; font-size: 12px; margin: 8px 0 0 0;">
        {{ __('mail.collaboration_invite.copy_link') }}<br>
        <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">{{ $acceptLink }}</code>
    </p>
@endif
@endsection
