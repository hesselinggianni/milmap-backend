@extends('emails.layout')

@section('title', 'Maak je eerste kaart')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Aan de slag · Dag 1
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', maak' : 'Maak' }} je eerste kaart
  </h1>

  <p style="margin:0 0 16px;">
    In een paar minuten heb je je eerste operationele kaart klaar. Zo doe je het:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Open MilMap en klik op <strong>Nieuwe kaart</strong>.</li>
    <li>Zoek je gebied en zet punten met MGRS-coördinaten.</li>
    <li>Importeer bestaande GPX/KML-tracks als je die hebt.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Maak een kaart
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
