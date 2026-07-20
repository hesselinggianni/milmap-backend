@extends('emails.layout')

@section('title', 'MilMapへようこそ')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    ご参加ありがとうございます
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . 'さん、MilMap' : 'MilMap' }}へようこそ
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="地図とウェルカム画面が表示されたMilMapアプリ" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    アカウントの準備が整いました。これから<strong>7日間</strong>、地図・ルートプランニング・ミッション・地形分析・暗号化されたチームコミュニケーションなど、すべての機能をご利用いただけます。期間終了後も基本機能は無料でお使いいただけます。
  </p>

  <p style="margin:0 0 22px;">
    まずはアプリを開いて、最初の地図を作成してみてください。明日、スムーズに始められる簡単なガイドをお送りします。
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          MilMapを開く
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
