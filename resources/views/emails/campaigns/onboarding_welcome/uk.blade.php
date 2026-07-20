@extends('emails.layout')

@section('title', 'Ласкаво просимо до MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Ласкаво просимо на борт
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Вітаємо, ' . $name . ',' : 'Вітаємо' }} у MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="Додаток MilMap із картою та вітальним екраном" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Ваш обліковий запис готовий. Найближчі <strong>7 днів</strong> у вас буде повний доступ до
    усіх функцій — карт, планування маршрутів, місій, аналізу місцевості та зашифрованого
    командного зв'язку. Після цього ви продовжите користуватися безкоштовними базовими функціями.
  </p>

  <p style="margin:0 0 22px;">
    Наша порада: відкрийте додаток і створіть свою першу карту. Завтра ми надішлемо вам
    короткий посібник, щоб ви швидко освоїлися.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Відкрити MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
