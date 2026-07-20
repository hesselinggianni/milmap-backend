@extends('emails.layout')

@section('title', 'Zaawansowane funkcje')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Idź dalej · Dzień 3
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    Siła MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_advanced.png" alt="Briefing misji i profil terenu w MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . ', możesz' : 'Możesz' }} znacznie więcej niż tylko rysować mapy.
    Te funkcje robią prawdziwą różnicę w terenie:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>Misje</strong> — planuj, przeprowadzaj odprawy i współpracuj według SMEAC.</li>
    <li><strong>MEDEVAC 9-liner</strong> — szybkie i uporządkowane zgłoszenia.</li>
    <li><strong>Analiza terenu</strong> — profil wysokości i linie widoczności.</li>
    <li><strong>Mapy offline</strong> — pracuj dalej bez połączenia.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Wypróbuj to
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Otrzymujesz tę wiadomość, ponieważ zarejestrowałeś się w MilMap.', 'unsubLabel' => 'Wypisz się z tych e-maili'])
@endsection
