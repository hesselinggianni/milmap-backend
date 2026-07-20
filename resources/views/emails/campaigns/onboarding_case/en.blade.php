@extends('emails.layout')

@section('title', 'MilMap in the field')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Customer story · Day 5
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    How units use MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_case.png" alt="Route card with checkpoints in MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    A reconnaissance unit used MilMap during a mountain exercise to plan routes, carry
    PDF route cards and navigate offline with no signal.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Route cards created and printed with MGRS coordinates.</li>
    <li>Terrain analysis of mountain passes and camps.</li>
    <li>Offline maps for use without a connection.</li>
    <li>Missions module for the O-group planning.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($siteUrl ?? 'https://milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Read more stories
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'You receive this email because you signed up with MilMap.', 'unsubLabel' => 'Unsubscribe from these emails'])
@endsection
