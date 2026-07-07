@extends('emails.layout')

@section('title', 'MilMap in het veld')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Klantcase · Dag 5
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    Zo gebruiken eenheden MilMap
  </h1>

  <p style="margin:0 0 16px;">
    Een verkenningseenheid gebruikte MilMap tijdens een bergoefening om routes te
    plannen, PDF-routekaarten mee te nemen en offline te navigeren zonder bereik.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Routekaarten met MGRS-coördinaten aangemaakt en geprint.</li>
    <li>Terreinanalyse van bergpassen en kampen.</li>
    <li>Offline kaarten voor gebruik zonder verbinding.</li>
    <li>Missions-module voor de O-group-planning.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($siteUrl ?? 'https://milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Lees meer verhalen
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
