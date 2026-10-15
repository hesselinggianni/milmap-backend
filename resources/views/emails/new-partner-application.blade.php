@extends('emails.layout-minimal')
@section('title', __('mail.new_partner_application.title'))
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.new_partner_application.title') }}</h1>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;margin:0 0 28px;">
    <tr>
      <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;vertical-align:top;">{{ __('mail.new_partner_application.name_label') }}</td>
      <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ trim(($partner->user->first_name ?? '') . ' ' . ($partner->user->last_name ?? '')) ?: '—' }}{{ $partner->company_name ? ' — ' . $partner->company_name : '' }}</td>
    </tr>
    <tr>
      <td style="padding:12px 0;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;vertical-align:top;">{{ __('mail.new_partner_application.email_label') }}</td>
      <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ $partner->user->email }}</td>
    </tr>
    @if($partner->website)
    <tr>
      <td style="padding:12px 0;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;vertical-align:top;">{{ __('mail.new_partner_application.website_label') }}</td>
      <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;word-break:break-all;">{{ $partner->website }}</td>
    </tr>
    @endif
    <tr>
      <td style="padding:12px 0;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;vertical-align:top;">{{ __('mail.new_partner_application.motivation_label') }}</td>
      <td style="padding:12px 0;font-size:15px;line-height:1.6;color:#01163d;border-bottom:1px solid #e5e7eb;white-space:pre-line;">{{ $partner->description }}</td>
    </tr>
  </table>
  <p style="margin:0;"><a href="https://admin.milmap.nl/partners" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.new_partner_application.cta') }}</a></p>
@endsection
