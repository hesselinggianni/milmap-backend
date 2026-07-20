@extends('emails.layout')

@section('title', 'Witamy w MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Witamy na pokładzie
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Witaj, ' . $name : 'Witamy' }} w MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="Aplikacja MilMap z mapą i ekranem powitalnym" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Twoje konto jest gotowe. Przez najbliższe <strong>7 dni</strong> masz pełny dostęp do
    wszystkich funkcji — map, planowania tras, misji, analizy terenu i szyfrowanej
    komunikacji zespołowej. Później korzystasz dalej bezpłatnie z funkcji podstawowych.
  </p>

  <p style="margin:0 0 22px;">
    Nasza wskazówka: otwórz aplikację i utwórz swoją pierwszą mapę. Jutro wyślemy Ci
    krótką instrukcję, dzięki której szybko zaczniesz działać.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Otwórz MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Otrzymujesz tę wiadomość, ponieważ zarejestrowałeś się w MilMap.', 'unsubLabel' => 'Wypisz się z tych e-maili'])
@endsection
