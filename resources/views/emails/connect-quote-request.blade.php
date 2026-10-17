@extends('emails.layout-minimal')
@section('title', 'Nieuwe Connect offerteaanvraag')
@section('body')
  <h1 style="margin:0 0 12px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">Nieuwe Connect offerteaanvraag</h1>
  <p style="margin:0 0 32px;"><strong style="color:#01163d;">{{ $quote['company_name'] }}</strong> vraagt een enterprise-offerte aan.</p>
  <p style="margin:0 0 28px;line-height:1.9;">
    <strong style="color:#01163d;">Contact:</strong> {{ $quote['contact_name'] }} ({{ $quote['email'] }})<br>
    <strong style="color:#01163d;">Facturatie e-mailadres:</strong> {{ $quote['billing_email'] ?? '—' }}<br>
    <strong style="color:#01163d;">Telefoon:</strong> {{ ($quote['phone'] ?? null) ?: '—' }}<br>
    <strong style="color:#01163d;">Licenties:</strong> {{ $quote['seat_count'] }}<br>
    <strong style="color:#01163d;">Doeleinde:</strong> {{ ($quote['purpose'] ?? null) ?: '—' }}
  </p>
  <p style="margin:0 0 8px;"><strong style="color:#01163d;">Specifieke wensen:</strong></p>
  <div style="padding:16px 18px;background:#f3f4f6;border-radius:8px;font-size:16px;line-height:1.7;color:#01163d;">{!! nl2br(e(($quote['requirements'] ?? null) ?: '—')) !!}</div>
  <p style="margin:24px 0 0;"><a href="https://admin.milmap.nl/sales">Bekijk Connect-aanmeldingen in Admin → Sales / CRM</a></p>
@endsection
