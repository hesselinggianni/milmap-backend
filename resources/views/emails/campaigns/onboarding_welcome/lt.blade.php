@extends('emails.layout')

@section('title', 'Sveiki atvykę į MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Sveiki prisijungę
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Sveiki, ' . $name : 'Sveiki' }} atvykę į MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="MilMap programėlė su žemėlapiu ir pasveikinimo ekranu" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Jūsų paskyra paruošta. Artimiausias <strong>7 dienas</strong> turėsite pilną prieigą
    prie visų funkcijų — žemėlapių, maršruto planavimo, misijų, vietovės analizės ir
    šifruoto komandos susirašinėjimo. Vėliau galėsite toliau naudotis nemokamomis
    bazinėmis funkcijomis.
  </p>

  <p style="margin:0 0 22px;">
    Mūsų patarimas: atidarykite programėlę ir sukurkite savo pirmąjį žemėlapį. Rytoj
    atsiųsime trumpą vadovą, kad greitai pradėtumėte darbą.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Atidaryti MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
