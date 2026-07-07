@extends('emails.layout')

@section('title', 'MilMap-tip')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#10b981;">
    Tip van de week
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', haal' : 'Haal' }} meer uit je kaarten
  </h1>

  <p style="margin:0 0 16px;">
    Wist je dat je hele routes als een filmische 3D-flyover kunt afspelen? Open een
    routekaart, tik op <strong>Flyover</strong> en de camera volgt automatisch het traject
    met realtime terreinhoogte — ideaal voor je briefing of debrief.
  </p>

  <p style="margin:0 0 22px;">
    Nog een tip: markeer onderweg punten met een lange druk op de kaart en deel ze direct
    versleuteld met je team.
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

  @include('emails.partials.unsubscribe-footer')
@endsection
