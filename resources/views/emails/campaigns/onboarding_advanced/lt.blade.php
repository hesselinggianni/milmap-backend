@extends('emails.layout')

@section('title', 'Pažangios funkcijos')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Žengkite toliau · 3 diena
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    MilMap galia
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_advanced.png" alt="Misijos instruktažas ir vietovės profilis MilMap programėlėje" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . ', galite' : 'Galite' }} daug daugiau nei tik piešti žemėlapius. Šios
    funkcijos iš tiesų daro skirtumą lauko sąlygomis:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>Misijos</strong> — planuokite, instruktuokite ir bendradarbiaukite pagal SMEAC.</li>
    <li><strong>MEDEVAC 9-liner</strong> — greitai ir struktūruotai pateikite užklausas.</li>
    <li><strong>Vietovės analizė</strong> — aukščio profilis ir matomumo linijos.</li>
    <li><strong>Neprisijungę žemėlapiai</strong> — dirbkite toliau be interneto ryšio.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Išbandykite dabar
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
