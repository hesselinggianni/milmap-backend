@extends('emails.layout')

@section('title', 'Sınırlı Süreli İndirim')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#22c55e;">
    Sınırlı süreli kampanya · Gün 14
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', hemen' : 'Hemen' }} geçiş yap — indirimle
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_discount.png" alt="MilMap Premium'da sınırlı süreli kampanya" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Hâlâ tereddüt mü ediyorsun? Sana küçük bir teşvik verelim: şimdi bir MilMap
    aboneliği al ve sınırlı süreli kampanyamızdan yararlan. Böylece sahada ve ofiste
    hiçbir kısıtlama olmadan çalışmaya devam edersin.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Sınırsız &amp; çevrimdışı haritalar</li>
    <li>Görevler, ekipler ve şifreli iletişim</li>
    <li>Rota haritası dışa aktarma ve arazi analizi</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#22c55e;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_yearly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Kampanyayı Gör
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Bu e-postayı MilMap\'e kaydolduğun için alıyorsun.', 'unsubLabel' => 'Bu e-postalardan çık'])
@endsection
