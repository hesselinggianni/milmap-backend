@extends('emails.layout')

@section('title', 'Welkom bij MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Welkom aan boord
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Welkom, ' . $name : 'Welkom' }} bij MilMap
  </h1>

  <p style="margin:0 0 16px;">
    Je account staat klaar. De komende <strong>7 dagen</strong> heb je volledige toegang tot
    álle functies — kaarten, routeplanning, missies, terreinanalyse en versleutelde
    team-comms. Daarna blijf je gratis verder met de basisfuncties.
  </p>

  <p style="margin:0 0 22px;">
    Onze tip: open de app en zet je eerste kaart op. Morgen sturen we je een korte
    handleiding om snel op weg te zijn.
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
