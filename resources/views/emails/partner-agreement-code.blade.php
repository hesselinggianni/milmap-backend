@extends('emails.layout')
@section('title', __('mail.partner_agreement_code.title'))
@section('body')

  <h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;">
    {{ __('mail.partner_agreement_code.title') }}
  </h1>

  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {{ __('mail.partner_agreement_code.intro') }}
  </p>

  {{-- Code --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 20px;background:#1a2433;border:1px solid #2a3a52;border-radius:10px;">
    <tr><td style="padding:20px;text-align:center;">
      <p style="margin:0 0 4px;font-size:13px;color:#94a3b8;">{{ __('mail.partner_agreement_code.code_label') }}</p>
      <p style="margin:0;font-size:32px;font-weight:800;letter-spacing:0.35em;color:#f8fafc;">{{ $code }}</p>
      <p style="margin:6px 0 0;font-size:12px;color:#64748b;">{{ __('mail.partner_agreement_code.code_validity') }}</p>
    </td></tr>
  </table>

  <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {{ __('mail.partner_agreement_code.cta_intro') }}
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
    <tr>
      <td style="border-radius:10px;background:#2b7fff;">
        <a href="{{ $confirmUrl }}"
           style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
          {{ __('mail.partner_agreement_code.cta') }}
        </a>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:12px;line-height:1.7;color:#64748b;">
    {{ __('mail.partner_agreement_code.footer_before') }}
    <a href="https://milmap.nl/#contact" style="color:#2b7fff;text-decoration:none;">{{ __('mail.partner_agreement_code.footer_link') }}</a>
    {{ __('mail.partner_agreement_code.footer_after') }}
  </p>

@endsection
