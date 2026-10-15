@extends('emails.layout-minimal')
@section('title', __('mail.contact_ticket.page_title'))
@section('body')
  <h1 style="margin:0 0 12px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.contact_ticket.title', ['id' => $ticket->id]) }}</h1>
  <p style="margin:0 0 32px;">{{ __('mail.contact_ticket.subtitle') }}</p>
  <p style="margin:0 0 8px;font-size:20px;font-weight:700;color:#01163d;">{{ $ticket->subject }}</p>
  <p style="margin:0 0 24px;font-size:14px;color:#6b7280;">{{ ucfirst($ticket->type) }}</p>
  <p style="margin:0 0 28px;line-height:1.9;">
    <strong style="color:#01163d;">{{ __('mail.contact_ticket.from') }}:</strong>
    {{ $ticket->name }} (<a href="mailto:{{ $ticket->email }}" style="color:#01163d;">{{ $ticket->email }}</a>)
  </p>
  <p style="margin:0 0 8px;"><strong style="color:#01163d;">{{ __('mail.contact_ticket.message') }}:</strong></p>
  <div style="margin:0 0 28px;padding:16px 18px;background:#f3f4f6;border-radius:8px;font-size:16px;line-height:1.7;color:#01163d;white-space:pre-line;">{{ $ticket->message }}</div>
  <p style="margin:0 0 8px;"><strong style="color:#01163d;">{{ __('mail.contact_ticket.details_title') }}</strong></p>
  <p style="margin:0;line-height:1.9;font-size:15px;">
    <strong style="color:#01163d;">Ticket ID:</strong> #{{ $ticket->id }}<br>
    <strong style="color:#01163d;">{{ __('mail.contact_ticket.status') }}:</strong> {{ __('mail.contact_ticket.status_open') }}<br>
    <strong style="color:#01163d;">{{ __('mail.contact_ticket.received') }}:</strong> {{ $ticket->created_at->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s') }}
  </p>
@endsection
