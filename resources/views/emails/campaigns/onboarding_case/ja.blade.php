@extends('emails.layout')

@section('title', '現場で活躍するMilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    導入事例 · 5日目
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    部隊はMilMapをこう活用しています
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_case.png" alt="MilMapのチェックポイント付きルートマップ" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    ある偵察部隊は山岳訓練の際にMilMapを使用し、ルートを計画し、PDFルートマップを携行し、電波のない環境でもオフラインでナビゲーションを行いました。
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>MGRS座標付きのルートマップを作成し印刷。</li>
    <li>山岳峠と野営地の地形分析。</li>
    <li>通信のない環境で使えるオフライン地図。</li>
    <li>O-groupの計画立案のためのミッションモジュール。</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($siteUrl ?? 'https://milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          他の事例を読む
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'このメールはMilMapにご登録いただいたため送信されています。', 'unsubLabel' => 'このメールの配信を停止する'])
@endsection
