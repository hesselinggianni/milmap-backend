@extends('emails.layout')
@section('title', __('mail.partner_application_received.subject'))
@section('body')

  <h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;">
    {{ __('mail.partner_application_received.title') }}
  </h1>

  <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {!! __('mail.partner_application_received.intro', [
        'for_company' => $partner->company_name
            ? __('mail.partner_application_received.for_company', [
                'company' => '<strong style="color:#f8fafc;">' . e($partner->company_name) . '</strong>',
              ])
            : '',
    ]) !!}
  </p>

  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {!! __('mail.partner_application_received.referral', [
        'code' => '<strong style="color:#f8fafc;">' . e($partner->referral_code) . '</strong>',
    ]) !!}
  </p>

  @if($setupUrl)
  <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {{ __('mail.partner_application_received.setup_intro') }}
  </p>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
    <tr>
      <td style="border-radius:10px;background:#2b7fff;">
        <a href="{{ $setupUrl }}"
           style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
          {{ __('mail.partner_application_received.setup_cta') }}
        </a>
      </td>
    </tr>
  </table>
  @endif

  <p style="margin:0;font-size:13px;line-height:1.7;color:#94a3b8;">
    {{ __('mail.partner_application_received.contact_before') }}
    <a href="https://milmap.nl/#contact" style="color:#2b7fff;text-decoration:none;font-weight:600;">{{ __('mail.partner_application_received.contact_link') }}</a>
    {{ __('mail.partner_application_received.contact_after') }}
  </p>

@endsection
