@extends('emails.layout')

@section('title', 'Advanced features')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Go further · Day 3
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    The power of MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_advanced.png" alt="Mission briefing and terrain profile in MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . ', you' : 'You' }} can do far more than draw maps. These features
    make the real difference in the field:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>Missions</strong> — plan, brief and collaborate using SMEAC.</li>
    <li><strong>MEDEVAC 9-liner</strong> — fast, structured requests.</li>
    <li><strong>Terrain analysis</strong> — elevation profiles and lines of sight.</li>
    <li><strong>Offline maps</strong> — keep working without a connection.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Try it out
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'You receive this email because you signed up with MilMap.', 'unsubLabel' => 'Unsubscribe from these emails'])
@endsection
