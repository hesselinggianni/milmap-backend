@extends('emails.layout')

@section('title', 'Become a MilMap member')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Ready for the next step?
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', become' : 'Become' }} a MilMap member today
  </h1>

  <p style="margin:0 0 16px;">
    Plan routes, navigate offline in the field and collaborate with your team — all in
    one place. A MilMap membership unlocks unlimited maps, PDF route books, terrain
    analysis and encrypted team comms.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Unlimited offline maps &amp; MGRS coordinates</li>
    <li>Route planning with elevation profile &amp; PDF export</li>
    <li>Encrypted team chat and mission collaboration</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Become a member
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'You receive this email because you signed up with MilMap.', 'unsubLabel' => 'Unsubscribe from these emails'])
@endsection
