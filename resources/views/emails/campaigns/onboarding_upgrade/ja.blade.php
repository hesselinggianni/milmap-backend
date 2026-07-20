@extends('emails.layout')

@section('title', 'Premiumへアップグレード')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    無料期間終了間近 · 7日目
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . 'さん、すべての機能を' : 'すべての機能を' }}維持しましょう
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="MilMap Premiumアップグレード画面" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    すべての機能をご利用いただける無料の7日間が終了します。ご契約がない場合も基本機能（地図は最大5件まで）は引き続き無料でご利用いただけますが、以下の機能はご利用いただけなくなります。
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>ミッション、チャット、MEDEVAC 9-liner</li>
    <li>無制限＆オフライン地図＋天気情報連携</li>
    <li>ルートマップのエクスポート、チーム機能、ライブ位置共有</li>
  </ul>

  <p style="margin:0 0 22px;">
    月額<strong>4.99ユーロ</strong>から、すべての機能をそのままご利用いただけます。いつでも解約可能です。
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Premiumへアップグレード
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
