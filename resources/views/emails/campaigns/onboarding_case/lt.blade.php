@extends('emails.layout')

@section('title', 'MilMap lauko sąlygomis')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Kliento atvejis · 5 diena
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    Taip padaliniai naudoja MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_case.png" alt="Maršruto žemėlapis su kontrolės punktais MilMap programėlėje" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Žvalgybos padalinys naudojo MilMap kalnų pratybų metu maršrutams planuoti, PDF
    maršruto žemėlapiams pasiimti su savimi ir navigacijai be interneto ryšio.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Sukurti ir atspausdinti maršruto žemėlapiai su MGRS koordinatėmis.</li>
    <li>Kalnų perėjų ir stovyklaviečių vietovės analizė.</li>
    <li>Neprisijungę žemėlapiai naudojimui be ryšio.</li>
    <li>Misijų modulis O-group planavimui.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($siteUrl ?? 'https://milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Skaityti daugiau istorijų
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Šį laišką gaunate, nes užsiregistravote MilMap.', 'unsubLabel' => 'Atsisakyti šių laiškų'])
@endsection
