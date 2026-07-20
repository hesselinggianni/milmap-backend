@extends('emails.layout')

@section('title', 'MilMap у полі')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Приклад клієнта · День 5
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    Ось як підрозділи використовують MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_case.png" alt="Маршрутна карта з контрольними точками в MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Розвідувальний підрозділ використовував MilMap під час гірських навчань для планування
    маршрутів, взяв із собою PDF-карти маршруту та навігував офлайн без покриття мережі.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Створено та роздруковано маршрутні карти з координатами MGRS.</li>
    <li>Аналіз місцевості гірських перевалів і таборів.</li>
    <li>Офлайн-карти для використання без з'єднання.</li>
    <li>Модуль Missions для планування O-group.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($siteUrl ?? 'https://milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Читати більше історій
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Ви отримуєте цей лист, оскільки зареєструвалися в MilMap.', 'unsubLabel' => 'Відписатися від цих листів'])
@endsection
