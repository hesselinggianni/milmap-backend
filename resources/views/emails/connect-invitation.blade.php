@extends('emails.layout-minimal')
@section('title', 'Welkom bij MilMap Connect')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">Welkom bij MilMap Connect</h1>
  <p style="margin:0 0 20px;">Hallo {{ $firstName }},</p>
  <p style="margin:0 0 20px;"><strong style="color:#01163d;">{{ $organizationName }}</strong> nodigt je uit om MilMap te gebruiken via de enterprise-licentie van de organisatie.</p>
  <p style="margin:0 0 20px;"><a href="{{ $inviteUrl }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">Uitnodiging accepteren</a></p>
  <p style="margin:0 0 20px;">Log in met het e-mailadres waarop je deze uitnodiging hebt ontvangen, of maak daarmee een account aan. De uitnodiging is 14 dagen geldig.</p>
  <p style="margin:0;">Na acceptatie kun je met hetzelfde account inloggen op de MilMap-app op je iPad, tablet of telefoon.</p>
@endsection
