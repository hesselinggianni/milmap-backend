@extends('emails.layout')

@section('title', "Premium'a Yükselt")

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    Deneme süren doluyor · Gün 7
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', tüm' : 'Tüm' }} özelliklerini koru
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="MilMap Premium yükseltme ekranı" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Tam erişimli 7 ücretsiz günün sona eriyor. Abonelik olmadan temel sürümle
    (en fazla 5 harita) ücretsiz devam edebilirsin, ancak şunları kaybedersin:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Görevler, sohbet ve MEDEVAC 9-liner</li>
    <li>Sınırsız &amp; çevrimdışı haritalar + hava durumu entegrasyonu</li>
    <li>Rota haritası dışa aktarma, ekipler ve canlı konum paylaşımı</li>
  </ul>

  <p style="margin:0 0 22px;">
    Ayda <strong>4,99 €</strong>'dan başlayarak her şeyi elinde tutarsın. İstediğin
    zaman iptal edilebilir.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Premium'a Yükselt
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
