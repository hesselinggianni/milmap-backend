@extends('emails.layout')
@section('title', __('mail.partner_payout.title'))
@section('body')

  <h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;">
    {{ __('mail.partner_payout.title') }}
  </h1>

  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    {{ __('mail.partner_payout.intro') }}
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 20px;background:#1a2433;border:1px solid #2a3a52;border-radius:10px;">
    <tr><td style="padding:18px;text-align:center;">
      <p style="margin:0 0 4px;font-size:13px;color:#94a3b8;">{{ __('mail.partner_payout.amount_label') }}</p>
      <p style="margin:0 0 4px;font-size:28px;font-weight:800;color:#f8fafc;">
        €{{ number_format($amount, 2, ',', '.') }}
      </p>
      <p style="margin:0;font-size:13px;color:#94a3b8;">
        {{ $commissionCount === 1
            ? __('mail.partner_payout.commission_one', ['count' => $commissionCount])
            : __('mail.partner_payout.commission_many', ['count' => $commissionCount]) }}
      </p>
    </td></tr>
  </table>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="border-radius:10px;background:#2b7fff;">
        <a href="{{ rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/') }}/commissies"
           style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
          {{ __('mail.partner_payout.cta') }}
        </a>
      </td>
    </tr>
  </table>

@endsection
