@extends('emails.layout')

@section('title', '最初の地図を作成しましょう')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    はじめましょう · 1日目
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . 'さん、最初' : '最初' }}の地図を作成しましょう
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_first_map.png" alt="MGRS座標で新しい地図を作成" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    数分で最初の作戦地図が完成します。手順は以下の通りです。
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>MilMapを開き、<strong>新しい地図</strong>をクリックします。</li>
    <li>対象エリアを検索し、MGRS座標でポイントを設定します。</li>
    <li>既存のGPX/KMLトラックがあればインポートします。</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          地図を作成する
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'このメールはMilMapにご登録いただいたため送信されています。', 'unsubLabel' => 'このメールの配信を停止する'])
@endsection
