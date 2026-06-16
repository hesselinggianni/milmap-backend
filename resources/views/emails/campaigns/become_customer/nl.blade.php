@extends('emails.layout')

@section('title', 'Word lid van MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Klaar voor de volgende stap?
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', word' : 'Word' }} vandaag nog lid van MilMap
  </h1>

  <p style="margin:0 0 16px;">
    Plan routes, navigeer offline in het veld en werk samen met je team — alles op één
    plek. Met een MilMap-abonnement krijg je onbeperkte kaarten, PDF-routeboeken,
    terreinanalyse en versleutelde team-comms.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Onbeperkte offline kaarten &amp; MGRS-coördinaten</li>
    <li>Routeplanning met hoogteprofiel &amp; PDF-export</li>
    <li>Versleutelde team-chat en missiesamenwerking</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Word lid van MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
