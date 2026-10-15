@extends('emails.layout-minimal')
@section('title', __('mail.new_lead.subject'))
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">
    {{ __('mail.new_lead.title') }}
  </h1>
  <p style="margin:0 0 36px;">{{ __('mail.new_lead.subtitle') }}</p>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_lead.email') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ $lead->email }}</td>
</tr>
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_lead.id') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;font-family:monospace;">{{ $lead->id }}</td>
</tr>
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_lead.source') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ $lead->source ?: __('mail.new_lead.unknown') }}</td>
</tr>
@if(!empty($lead->utm_source))
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_lead.utm_source') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;word-break:break-all;">{{ $lead->utm_source }}</td>
</tr>
@endif
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_lead.date') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ optional($lead->created_at)->format('d-m-Y H:i') ?? now()->format('d-m-Y H:i') }}</td>
</tr>
  </table>
@endsection
