@extends('emails.layout')

@section('title', 'Tymczasowa zniżka')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#22c55e;">
    Oferta tymczasowa · Dzień 14
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', przejdź' : 'Przejdź' }} teraz — ze zniżką
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_discount.png" alt="Tymczasowa oferta na MilMap Premium" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Wciąż się wahasz? Dajemy Ci dodatkową zachętę: wykup teraz subskrypcję MilMap i
    skorzystaj z naszej tymczasowej oferty. Dzięki temu będziesz pracować bez ograniczeń —
    zarówno w terenie, jak i w biurze.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Nieograniczone i offline mapy</li>
    <li>Misje, zespoły i szyfrowana komunikacja</li>
    <li>Eksport map trasy i analiza terenu</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#22c55e;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_yearly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Zobacz ofertę
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Otrzymujesz tę wiadomość, ponieważ zarejestrowałeś się w MilMap.', 'unsubLabel' => 'Wypisz się z tych e-maili'])
@endsection
