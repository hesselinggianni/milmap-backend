@extends('emails.layout')

@section('title', "MilMap'e Hoş Geldiniz")

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Aramıza hoş geldiniz
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Hoş geldin, ' . $name : 'Hoş geldin' }}, MilMap'tesin
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="Harita ve hoş geldiniz ekranını gösteren MilMap uygulaması" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Hesabın hazır. Önümüzdeki <strong>7 gün</strong> boyunca haritalar, rota planlama,
    görevler, arazi analizi ve şifreli ekip iletişimi dahil tüm özelliklere tam erişimin
    var. Bu süreden sonra temel özelliklerle ücretsiz kullanmaya devam edebilirsin.
  </p>

  <p style="margin:0 0 22px;">
    Tavsiyemiz: uygulamayı aç ve ilk haritanı oluştur. Yarın seni hızla yola koymak için
    kısa bir kılavuz göndereceğiz.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          MilMap'i Aç
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
