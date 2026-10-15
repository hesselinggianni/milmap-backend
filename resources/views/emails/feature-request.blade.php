@extends('emails.layout-minimal')
@section('title', __('mail.feature_request.page_title'))
@section('body')
  <h1 style="margin:0 0 12px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.feature_request.title') }}</h1>
  <p style="margin:0 0 32px;">{{ __('mail.feature_request.subtitle') }}</p>
  <p style="margin:0 0 8px;font-size:20px;font-weight:700;color:#01163d;">{{ $data['title'] }}</p>
  @if(!empty($data['category']))
  <p style="margin:0 0 24px;font-size:14px;color:#6b7280;">Categorie: {{ $data['category'] }}</p>
  @else
  <div style="height:16px;"></div>
  @endif
  <p style="margin:0 0 28px;line-height:1.9;">
    <strong style="color:#01163d;">{{ __('mail.feature_request.submitted_by') }}:</strong>
    {{ $data['user_name'] }} (<a href="mailto:{{ $data['user_email'] }}" style="color:#01163d;">{{ $data['user_email'] }}</a>)
  </p>
  <p style="margin:0 0 8px;"><strong style="color:#01163d;">{{ __('mail.feature_request.description') }}:</strong></p>
  <div style="margin:0 0 28px;padding:16px 18px;background:#f3f4f6;border-radius:8px;font-size:16px;line-height:1.7;color:#01163d;white-space:pre-line;">{{ $data['description'] }}</div>
  <p style="margin:0 0 8px;"><strong style="color:#01163d;">{{ __('mail.feature_request.details_title') }}</strong></p>
  <p style="margin:0;line-height:1.9;font-size:15px;">
    <strong style="color:#01163d;">{{ __('mail.feature_request.priority') }}:</strong>
    @if($data['priority'] === 'high'){{ __('mail.feature_request.priority_high') }}@elseif($data['priority'] === 'medium'){{ __('mail.feature_request.priority_medium') }}@else{{ __('mail.feature_request.priority_low') }}@endif<br>
    <strong style="color:#01163d;">{{ __('mail.feature_request.time') }}:</strong> {{ $data['timestamp'] }}
  </p>
@endsection
