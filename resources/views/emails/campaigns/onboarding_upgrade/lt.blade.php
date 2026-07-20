@extends('emails.layout')

@section('title', 'Pereikite į „Premium“')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    Jūsų bandomasis laikotarpis baigiasi · 7 diena
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', išsaugokite' : 'Išsaugokite' }} visas savo funkcijas
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="MilMap „Premium“ atnaujinimo ekranas" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Jūsų 7 nemokamos dienos su pilna prieiga baigiasi. Be prenumeratos galėsite toliau
    naudotis nemokamomis bazinėmis funkcijomis (iki 5 žemėlapių), tačiau prarasite, be
    kita ko:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Misijas, pokalbius ir MEDEVAC 9-liner</li>
    <li>Neribotus ir neprisijungusius žemėlapius bei orų integraciją</li>
    <li>Maršruto žemėlapio eksportą, komandas ir gyvos vietos bendrinimą</li>
  </ul>

  <p style="margin:0 0 22px;">
    Nuo <strong>4,99 €/mėn.</strong> viską išlaikysite. Visada galite atsisakyti bet kada.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Pereiti į „Premium“
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
