@extends('emails.layout')
@section('title', 'Plan je route — Milmap')
@section('body')

  {{-- Intro --}}
  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#2b7fff;">
    Product-update
  </p>
  <h1 style="margin:0 0 8px;font-size:23px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
    Plan je route — nu in Milmap
  </h1>
  <p style="margin:0 0 22px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    Vanaf nu plan je complete routes binnen Milmap. Importeer je voorbereiding rechtstreeks uit
    <strong style="color:#f8fafc;">TopoGPS</strong>, verfijn alles op de militaire kaart en exporteer naar je
    <strong style="color:#f8fafc;">Garmin</strong> om de route onderweg live te tracken — in één doorlopende workflow.
  </p>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  <p style="margin:0 0 16px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Zo werkt het
  </p>

  {{-- Stap 1 --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:14px;">
    <tr>
      <td style="vertical-align:top;width:44px;">
        <div style="width:34px;height:34px;background:#1a2433;border:1px solid #2a3a52;border-radius:9px;
                    text-align:center;line-height:34px;color:#2b7fff;font-size:14px;font-weight:700;">1</div>
      </td>
      <td style="vertical-align:top;padding-left:14px;">
        <p style="margin:0 0 3px;font-size:14px;font-weight:700;color:#f8fafc;">Importeer uit TopoGPS</p>
        <p style="margin:0;font-size:13px;line-height:1.6;color:#94a3b8;">
          Haal je routeplanning, routekaarten, weer, afstanden, hoogteprofiel en declinatie in één keer binnen.
        </p>
      </td>
    </tr>
  </table>

  {{-- Stap 2 --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:14px;">
    <tr>
      <td style="vertical-align:top;width:44px;">
        <div style="width:34px;height:34px;background:#1a2433;border:1px solid #2a3a52;border-radius:9px;
                    text-align:center;line-height:34px;color:#2b7fff;font-size:14px;font-weight:700;">2</div>
      </td>
      <td style="vertical-align:top;padding-left:14px;">
        <p style="margin:0 0 3px;font-size:14px;font-weight:700;color:#f8fafc;">Plan &amp; verfijn in Milmap</p>
        <p style="margin:0;font-size:13px;line-height:1.6;color:#94a3b8;">
          Stel je route samen, controleer afstanden en hoogtemeters en leg waypoints vast op de militaire kaart.
        </p>
      </td>
    </tr>
  </table>

  {{-- Stap 3 --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:44px;">
        <div style="width:34px;height:34px;background:#1a2433;border:1px solid #2a3a52;border-radius:9px;
                    text-align:center;line-height:34px;color:#2b7fff;font-size:14px;font-weight:700;">3</div>
      </td>
      <td style="vertical-align:top;padding-left:14px;">
        <p style="margin:0 0 3px;font-size:14px;font-weight:700;color:#f8fafc;">Exporteer naar Garmin</p>
        <p style="margin:0;font-size:13px;line-height:1.6;color:#94a3b8;">
          Stuur de route naar je Garmin-toestel en track 'm onderweg — ook offline in het veld.
        </p>
      </td>
    </tr>
  </table>

  {{-- Feature-chips --}}
  <div style="background:#0d1320;border:1px solid #1e293b;border-radius:10px;padding:14px 16px;margin:0 0 26px;">
    <p style="margin:0 0 10px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
      Alles in één route
    </p>
    @php
      $features = ['Routeplanning', 'Routekaarten', 'Weer', 'Afstanden', 'Hoogteprofiel', 'Declinatie', 'Garmin-export'];
    @endphp
    @foreach ($features as $feature)
      <span style="display:inline-block;margin:0 6px 6px 0;padding:5px 11px;background:#1a2433;border:1px solid #2a3a52;
                   border-radius:999px;font-size:12px;font-weight:600;color:#cbd5e1;">{{ $feature }}</span>
    @endforeach
  </div>

  {{-- CTA --}}
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="border-radius:8px;background:#2b7fff;">
        <a href="{{ $appUrl }}"
           style="display:inline-block;height:44px;padding:0 26px;line-height:44px;
                  font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
          Plan je route in Milmap
        </a>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 20px;"></div>

  <p style="margin:0;font-size:13px;color:#94a3b8;line-height:1.6;">
    Vragen of feedback over routeplanning? Beantwoord deze e-mail — we lezen alles.
  </p>

@endsection
