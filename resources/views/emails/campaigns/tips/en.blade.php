@extends('emails.layout')

@section('title', 'Milmap tip')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#10b981;">
    Tip of the week
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', get' : 'Get' }} more out of your maps
  </h1>

  <p style="margin:0 0 16px;">
    Did you know you can play back entire routes as a cinematic 3D flyover? Open a route
    map, tap <strong>Flyover</strong> and the camera automatically follows the track with
    real-time terrain elevation — perfect for your briefing or debrief.
  </p>

  <p style="margin:0 0 22px;">
    One more tip: drop waypoints on the move with a long-press on the map and share them
    end-to-end encrypted with your team instantly.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#10b981;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Open Milmap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'You receive this email because you signed up with MilMap.', 'unsubLabel' => 'Unsubscribe from these emails'])
@endsection
