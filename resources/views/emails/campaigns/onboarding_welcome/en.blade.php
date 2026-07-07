@extends('emails.layout')

@section('title', 'Welcome to MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Welcome aboard
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Welcome, ' . $name : 'Welcome' }} to MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="MilMap app showing a map and welcome screen" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Your account is ready. For the next <strong>7 days</strong> you have full access to
    every feature — maps, route planning, missions, terrain analysis and encrypted
    team comms. After that you keep going for free with the basics.
  </p>

  <p style="margin:0 0 22px;">
    Our tip: open the app and set up your first map. Tomorrow we'll send a short guide
    to get you up to speed.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Open MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
