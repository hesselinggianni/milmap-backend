@extends('emails.layout')

@section('title', 'Upgrade to Premium')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    Your trial is ending · Day 7
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', keep' : 'Keep' }} all your features
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="MilMap Premium upgrade screen" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Your 7 free days of full access are ending. Without a subscription you keep going for
    free with the basics (max 5 maps), but you'll lose, among others:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Missions, chat and the MEDEVAC 9-liner</li>
    <li>Unlimited &amp; offline maps + weather integration</li>
    <li>Route-card export, teams and live location sharing</li>
  </ul>

  <p style="margin:0 0 22px;">
    From <strong>€4.99/month</strong> you keep everything. Cancel anytime.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Upgrade to Premium
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
