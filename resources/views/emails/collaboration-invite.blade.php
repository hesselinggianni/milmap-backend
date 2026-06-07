@extends('emails.layout')

@section('body')
<div style="padding: 24px 0; text-align: center;">
    <h2 style="color: #f8fafc; margin: 0 0 16px 0; font-size: 24px; font-weight: bold;">
        You're invited to collaborate!
    </h2>
    <p style="color: #cbd5e1; margin: 0 0 24px 0; font-size: 16px;">
        {{ $inviter->first_name }} invited you to collaborate on <strong>{{ $map->title }}</strong>
    </p>
</div>

<div style="background: #f5f5f5; padding: 24px; border-radius: 8px; margin: 24px 0;">
    <h3 style="color: #f8fafc; margin: 0 0 12px 0;">What can you do?</h3>
    <ul style="color: #cbd5e1; margin: 0; padding-left: 20px;">
        <li style="margin: 8px 0;">Create and edit route maps</li>
        <li style="margin: 8px 0;">Add and manage location markers</li>
        <li style="margin: 8px 0;">See real-time updates from other collaborators</li>
        <li style="margin: 8px 0;">Share your live location with the team</li>
    </ul>
</div>

<div style="margin: 32px 0; text-align: center;">
    <a href="{{ $acceptLink }}" style="display: inline-block; padding: 12px 32px; background: #2b7fff; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
        Accept Invitation
    </a>
</div>

<div style="background: #1a2433; padding: 16px; border-left: 4px solid #2b7fff; border-radius: 4px; margin: 24px 0;">
    <p style="color: #cbd5e1; margin: 0; font-size: 14px;">
        <strong>Privacy Notice:</strong> Only the map owner can remove your access. Location sharing is per-session and requires your permission each time.
    </p>
</div>

<hr style="border: none; border-top: 1px solid #1e293b; margin: 24px 0;">

<p style="color: #7e8a9c; font-size: 12px; margin: 0;">
    If you have questions, you can reply to this email or contact {{ $inviter->email }}.
</p>

@if(!$acceptLink)
    <p style="color: #7e8a9c; font-size: 12px; margin: 8px 0 0 0;">
        Or copy and paste this link in your browser:<br>
        <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">{{ $acceptLink }}</code>
    </p>
@endif
@endsection
