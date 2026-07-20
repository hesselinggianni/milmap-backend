@extends('emails.layout')

@section('title', '高度な機能のご紹介')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    さらに活用する · 3日目
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    MilMapの真価
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_advanced.png" alt="MilMapのミッションブリーフィングと地形プロファイル" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . 'さんは' : '' }}地図の作成だけでなく、現場で本当に差がつく機能もご利用いただけます。
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>ミッション</strong> — SMEACに沿って計画・ブリーフィング・共同作業。</li>
    <li><strong>MEDEVAC 9-liner</strong> — 迅速かつ体系的に要請できます。</li>
    <li><strong>地形分析</strong> — 高度プロファイルと視認範囲を確認。</li>
    <li><strong>オフライン地図</strong> — 通信がなくても作業を継続。</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          試してみる
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'このメールはMilMapにご登録いただいたため送信されています。', 'unsubLabel' => 'このメールの配信を停止する'])
@endsection
