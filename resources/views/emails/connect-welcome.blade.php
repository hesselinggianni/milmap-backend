@extends('emails.layout-minimal')
@section('title', 'Welkom bij MilMap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">Welkom bij MilMap, {{ $firstName }}</h1>
  <p style="margin:0 0 20px;">Je kunt MilMap gebruiken via het abonnement van <strong style="color:#01163d;">{{ $organizationName }}</strong>.</p>
  <p style="margin:0 0 28px;line-height:1.9;">
    <strong style="color:#01163d;">E-mailadres:</strong> {{ $email }}<br>
    <strong style="color:#01163d;">Tijdelijk wachtwoord:</strong> {{ $password }}
  </p>
  <p style="margin:0 0 20px;"><a href="https://app.milmap.nl/login" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">Inloggen</a></p>
  <p style="margin:0;">Wijzig je wachtwoord na het inloggen.</p>
@endsection
