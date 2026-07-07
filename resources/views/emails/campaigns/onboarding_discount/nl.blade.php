@extends('emails.layout')

@section('title', 'Tijdelijke korting')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#22c55e;">
    Tijdelijke actie · Dag 14
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', stap' : 'Stap' }} nu over — met korting
  </h1>

  <p style="margin:0 0 16px;">
    Nog steeds twijfel je? We geven je een extra zetje: neem nu een MilMap-abonnement en
    profiteer van onze tijdelijke actie. Zo werk je zonder beperkingen verder — in het
    veld én op kantoor.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Onbeperkte &amp; offline kaarten</li>
    <li>Missies, teams en versleutelde comms</li>
    <li>Routekaart-export en terreinanalyse</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#22c55e;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_yearly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Bekijk de actie
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
