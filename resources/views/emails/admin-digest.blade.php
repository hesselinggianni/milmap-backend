@extends('emails.layout-minimal')
@section('title', $subject)
@section('maxwidth', '640')
@section('body')
@php
  $t = fn (string $key, array $rep = []) => __('mail.admin_digest.' . $key, $rep);
  $unknown = $t('unknown');

  // "Nederland (NL)" voor bekende landen, anders de kale code; leeg → Onbekend.
  $countryLabel = function (?string $code) use ($countryName, $unknown) {
      if (! $code || $code === '??') return $unknown;
      $name = $countryName($code);
      return $name === $code ? $code : $name . ' (' . $code . ')';
  };

  // Tijdstip: bij een dagoverzicht volstaat het kloktijdstip.
  $timeFmt = $kind === 'day' ? 'H:i' : 'j M H:i';
  $timeOf  = fn ($at) => $at->locale($locale)->translatedFormat($timeFmt);

  $planLabel = fn (string $plan) => $t('plans.' . $plan);
  $isPaid    = fn (string $plan) => ! in_array($plan, ['trial', 'free'], true);

  // Verschil met de vorige periode: +3 (groen) / −2 (rood) / ±0 (grijs).
  $delta = function (int $now, int $before) {
      $diff = $now - $before;
      if ($diff > 0) return ['+' . $diff, '#15803d'];
      if ($diff < 0) return ['−' . abs($diff), '#b91c1c'];
      return ['±0', '#6b7280'];
  };

  $kpis = [
      ['kpi_signups',       $d['signups']['total'],       $d['previous']['signups']],
      ['kpi_leads',         $d['leads']['total'],         $d['previous']['leads']],
      ['kpi_subscriptions', $d['subscriptions']['total'], $d['previous']['subscriptions']],
      ['kpi_cancellations', $d['cancellations']['total'], $d['previous']['cancellations']],
  ];

  $label  = 'font-size:13px;color:#6b7280;';
  $h2     = 'margin:44px 0 6px;font-size:21px;line-height:1.25;font-weight:800;color:#01163d;letter-spacing:-0.01em;';
  $cell   = 'padding:14px 0;border-bottom:1px solid #e5e7eb;';
  $pill   = 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;';
@endphp

  <h1 style="margin:0 0 6px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">
    {{ $t('title.' . $kind) }}
  </h1>
  <p style="margin:0 0 32px;font-size:16px;color:#6b7280;">{{ $periodLong }}</p>

  {{-- Cijfers van de periode, naast de vorige periode (2×2: blijft leesbaar op een telefoon) --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
    @foreach(array_chunk($kpis, 2) as $kpiRow)
      <tr>
        @foreach($kpiRow as [$labelKey, $now, $before])
          @php [$deltaText, $deltaColor] = $delta($now, $before); @endphp
          <td valign="top" width="50%" style="padding:18px 12px 18px 0;border-bottom:1px solid #e5e7eb;">
            <div style="font-size:34px;line-height:1.1;font-weight:800;color:#01163d;">{{ $now }}</div>
            <div style="margin-top:4px;{{ $label }}">{{ $t($labelKey) }}</div>
            <div style="margin-top:6px;font-size:12px;color:#9ca3af;"><span style="color:{{ $deltaColor }};font-weight:700;">{{ $deltaText }}</span> · {{ $t('previous_label.' . $kind) }}: {{ $before }}</div>
          </td>
        @endforeach
      </tr>
    @endforeach
  </table>

  {{-- Totaalstand --}}
  <h2 style="{{ $h2 }}">{{ $t('totals_title') }}</h2>
  @php
    $tot = $d['totals'];
    // Gebruikers (totaal), daaronder hoe ze zich verdelen: betalend (per plan), op proef, gratis.
    $sub = 'padding:10px 0 10px 16px;border-bottom:1px solid #e5e7eb;font-size:15px;color:#6b7280;';
    $num = 'padding:10px 0;border-bottom:1px solid #e5e7eb;font-size:15px;font-weight:700;color:#01163d;';
    $fmt = fn (int $n) => number_format($n, 0, ',', '.');
  @endphp
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
    <tr>
      <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-size:15px;font-weight:700;color:#01163d;">{{ $t('totals_users') }}</td>
      <td align="right" style="{{ $num }}">{{ $fmt($tot['users']) }}</td>
    </tr>
    <tr>
      <td style="{{ $sub }}">{{ $t('totals_paying') }}</td>
      <td align="right" style="{{ $num }}">{{ $fmt($tot['users_paying']) }}</td>
    </tr>
    @foreach($tot['paid_by_plan'] as $plan => $count)
      <tr>
        <td style="padding:8px 0 8px 32px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">{{ $planLabel($plan) }}</td>
        <td align="right" style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:13px;color:#4b5563;">{{ $fmt($count) }}</td>
      </tr>
    @endforeach
    <tr>
      <td style="{{ $sub }}">{{ $t('totals_on_trial') }}</td>
      <td align="right" style="{{ $num }}">{{ $fmt($tot['users_on_trial']) }}</td>
    </tr>
    <tr>
      <td style="{{ $sub }}">{{ $t('totals_free') }}</td>
      <td align="right" style="{{ $num }}">{{ $fmt($tot['users_free']) }}</td>
    </tr>
    <tr>
      <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-size:15px;font-weight:700;color:#01163d;">{{ $t('totals_leads') }}</td>
      <td align="right" style="{{ $num }}">{{ $fmt($tot['leads']) }}</td>
    </tr>
  </table>

  {{-- Verloop binnen de periode (alles behalve een dagoverzicht) --}}
  @if(! empty($d['trend']))
    @php
      $maxSignups = max(1, collect($d['trend'])->max('signups'));
      $trendLabel = function ($b) use ($kind, $locale) {
          $last = $b['to']->subDay();
          if ($kind === 'week')  return $b['from']->locale($locale)->translatedFormat('D j M');
          if ($kind === 'month') return $b['from']->isSameDay($last)
              ? $b['from']->locale($locale)->translatedFormat('j M')
              : $b['from']->locale($locale)->translatedFormat('j M') . ' – ' . $last->locale($locale)->translatedFormat('j M');
          return $b['from']->locale($locale)->translatedFormat('F');
      };
    @endphp
    <h2 style="{{ $h2 }}">{{ $t('trend_title') }}</h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
      <tr>
        <td style="padding:8px 0;{{ $label }}border-bottom:1px solid #e5e7eb;">&nbsp;</td>
        <td style="padding:8px 8px;{{ $label }}border-bottom:1px solid #e5e7eb;width:34%;">{{ $t('trend_signups') }}</td>
        <td align="right" style="padding:8px 0 8px 8px;{{ $label }}border-bottom:1px solid #e5e7eb;">{{ $t('trend_leads') }}</td>
        <td align="right" style="padding:8px 0 8px 12px;{{ $label }}border-bottom:1px solid #e5e7eb;">{{ $t('trend_subscriptions') }}</td>
      </tr>
      @foreach($d['trend'] as $bucket)
        <tr>
          <td style="padding:9px 0;border-bottom:1px solid #e5e7eb;font-size:14px;color:#01163d;white-space:nowrap;">{{ $trendLabel($bucket) }}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#01163d;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
              <td width="28" style="font-weight:700;">{{ $bucket['signups'] }}</td>
              <td><div style="height:8px;border-radius:4px;background:#01163d;width:{{ round($bucket['signups'] / $maxSignups * 100) }}%;min-width:{{ $bucket['signups'] > 0 ? 4 : 0 }}px;"></div></td>
            </tr></table>
          </td>
          <td align="right" style="padding:9px 0 9px 8px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#4b5563;">{{ $bucket['leads'] }}</td>
          <td align="right" style="padding:9px 0 9px 12px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#4b5563;">{{ $bucket['subscriptions'] }}</td>
        </tr>
      @endforeach
    </table>
  @endif

  {{-- Nieuwe aanmeldingen: wie, welk abonnement, waar vandaan, welk land --}}
  <h2 style="{{ $h2 }}">{{ $t('signups_title') }} <span style="color:#9ca3af;font-weight:600;">({{ $d['signups']['total'] }})</span></h2>
  @if($d['signups']['total'] === 0)
    <p style="margin:0;font-size:15px;color:#6b7280;">{{ $t('signups_empty') }}</p>
  @else
    <p style="margin:0 0 10px;font-size:12px;color:#9ca3af;">{{ $t('plan_note') }}</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
      @foreach($d['signups']['rows'] as $row)
        <tr>
          <td style="{{ $cell }}">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="font-size:15px;font-weight:700;color:#01163d;word-break:break-all;">
                  {{ $row['email'] }}
                  @if($row['name'])<span style="font-weight:500;color:#6b7280;"> · {{ $row['name'] }}</span>@endif
                </td>
                <td align="right" valign="top" style="padding-left:12px;">
                  <span style="{{ $pill }}{{ $isPaid($row['plan']) ? 'background:#01163d;color:#ffffff;' : 'background:#f3f4f6;color:#01163d;' }}">{{ $planLabel($row['plan']) }}</span>
                </td>
              </tr>
              <tr>
                <td colspan="2" style="padding-top:5px;font-size:13px;line-height:1.55;color:#6b7280;word-break:break-all;">
                  {{ $timeOf($row['at']) }}
                  · {{ $t('country') }}: <span style="color:#374151;">{{ $countryLabel($row['country']) }}</span>
                  · {{ $t('via') }}: <span style="color:#374151;">{{ $row['url'] ? \Illuminate\Support\Str::limit($shortUrl($row['url']), 80) : $unknown }}</span>
                  @if($row['referrer'])· {{ $t('referrer') }}: <span style="color:#374151;">{{ \Illuminate\Support\Str::limit($shortUrl($row['referrer']), 60) }}</span>@endif
                  · {{ $t('methods.' . $row['method']) }}
                </td>
              </tr>
            </table>
          </td>
        </tr>
      @endforeach
    </table>
    @if($d['signups']['total'] > count($d['signups']['rows']))
      <p style="margin:10px 0 0;font-size:13px;color:#6b7280;">{{ $t('more', ['count' => $d['signups']['total'] - count($d['signups']['rows'])]) }}</p>
    @endif
  @endif

  {{-- Nieuwe leads --}}
  <h2 style="{{ $h2 }}">{{ $t('leads_title') }} <span style="color:#9ca3af;font-weight:600;">({{ $d['leads']['total'] }})</span></h2>
  @if($d['leads']['total'] === 0)
    <p style="margin:0;font-size:15px;color:#6b7280;">{{ $t('leads_empty') }}</p>
  @else
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
      @foreach($d['leads']['rows'] as $row)
        <tr>
          <td style="{{ $cell }}">
            <div style="font-size:15px;font-weight:700;color:#01163d;word-break:break-all;">
              {{ $row['email'] }}
            </div>
            <div style="padding-top:5px;font-size:13px;line-height:1.55;color:#6b7280;word-break:break-all;">
              {{ $timeOf($row['at']) }}
              · {{ $t('country') }}: <span style="color:#374151;">{{ $countryLabel($row['country']) }}</span>
              · {{ $t('via') }}: <span style="color:#374151;">{{ $row['url'] ? \Illuminate\Support\Str::limit($shortUrl($row['url']), 80) : ($row['source'] ?: $unknown) }}</span>
              @if($row['referrer'])· {{ $t('referrer') }}: <span style="color:#374151;">{{ \Illuminate\Support\Str::limit($shortUrl($row['referrer']), 60) }}</span>@endif
              @if($row['step'])· {{ $t('lead_step', ['step' => $row['step']]) }}@endif
            </div>
          </td>
        </tr>
      @endforeach
    </table>
    @if($d['leads']['total'] > count($d['leads']['rows']))
      <p style="margin:10px 0 0;font-size:13px;color:#6b7280;">{{ $t('more', ['count' => $d['leads']['total'] - count($d['leads']['rows'])]) }}</p>
    @endif
  @endif

  {{-- Nieuwe abonnementen + opzeggingen --}}
  <h2 style="{{ $h2 }}">{{ $t('subscriptions_title') }} <span style="color:#9ca3af;font-weight:600;">({{ $d['subscriptions']['total'] }})</span></h2>
  @if($d['subscriptions']['total'] === 0)
    <p style="margin:0;font-size:15px;color:#6b7280;">{{ $t('subscriptions_empty') }}</p>
  @else
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
      @foreach($d['subscriptions']['rows'] as $row)
        <tr>
          <td style="{{ $cell }}font-size:15px;font-weight:700;color:#01163d;word-break:break-all;">
            {{ $row['email'] }}
            <div style="font-size:13px;font-weight:400;color:#6b7280;">{{ $timeOf($row['at']) }}</div>
          </td>
          <td align="right" valign="top" style="{{ $cell }}padding-left:12px;">
            <span style="{{ $pill }}background:#01163d;color:#ffffff;">{{ $planLabel($row['plan']) }}</span>
          </td>
        </tr>
      @endforeach
    </table>
    @if($d['subscriptions']['total'] > count($d['subscriptions']['rows']))
      <p style="margin:10px 0 0;font-size:13px;color:#6b7280;">{{ $t('more', ['count' => $d['subscriptions']['total'] - count($d['subscriptions']['rows'])]) }}</p>
    @endif
  @endif

  @if($d['cancellations']['total'] > 0)
    <h2 style="{{ $h2 }}">{{ $t('cancellations_title') }} <span style="color:#9ca3af;font-weight:600;">({{ $d['cancellations']['total'] }})</span></h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
      @foreach($d['cancellations']['rows'] as $row)
        <tr>
          <td style="{{ $cell }}font-size:15px;font-weight:700;color:#01163d;word-break:break-all;">
            {{ $row['email'] }}
            <div style="font-size:13px;font-weight:400;color:#6b7280;">{{ $timeOf($row['at']) }}</div>
          </td>
          <td align="right" valign="top" style="{{ $cell }}padding-left:12px;">
            <span style="{{ $pill }}background:#fee2e2;color:#991b1b;">{{ $planLabel($row['plan']) }}</span>
          </td>
        </tr>
      @endforeach
    </table>
    @if($d['cancellations']['total'] > count($d['cancellations']['rows']))
      <p style="margin:10px 0 0;font-size:13px;color:#6b7280;">{{ $t('more', ['count' => $d['cancellations']['total'] - count($d['cancellations']['rows'])]) }}</p>
    @endif
  @endif

  {{-- Waar komen ze vandaan: landen en pagina's --}}
  @php
    $b = $d['breakdown'];
    $originBlocks = [
        ['origin_signups_country', $b['signups_by_country'], true],
        ['origin_signups_source',  $b['signups_by_source'],  false],
        ['origin_leads_country',   $b['leads_by_country'],   true],
        ['origin_leads_source',    $b['leads_by_source'],    false],
    ];
    $hasOrigin = collect($originBlocks)->contains(fn ($blk) => ! empty($blk[1]['rows']));
  @endphp
  @if($hasOrigin)
    <h2 style="{{ $h2 }}">{{ $t('origin_title') }}</h2>
    @foreach($originBlocks as [$blockKey, $block, $isCountry])
      @continue(empty($block['rows']))
      @php $blockMax = max(1, collect($block['rows'])->max('count')); @endphp
      <p style="margin:22px 0 6px;font-size:13px;font-weight:700;color:#01163d;text-transform:uppercase;letter-spacing:0.04em;">{{ $t($blockKey) }}</p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
        @foreach($block['rows'] as $r)
          <tr>
            <td style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;color:#01163d;word-break:break-all;width:46%;">
              {{ $isCountry ? $countryLabel($r['label']) : ($r['label'] !== '' ? $r['label'] : $unknown) }}
            </td>
            <td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">
              <div style="height:8px;border-radius:4px;background:#01163d;width:{{ round($r['count'] / $blockMax * 100) }}%;min-width:4px;"></div>
            </td>
            <td align="right" style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:700;color:#01163d;width:40px;">{{ $r['count'] }}</td>
          </tr>
        @endforeach
        @if($block['other'] > 0)
          <tr>
            <td style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;color:#6b7280;">{{ $t('origin_other') }}</td>
            <td style="border-bottom:1px solid #e5e7eb;">&nbsp;</td>
            <td align="right" style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;color:#6b7280;">{{ $block['other'] }}</td>
          </tr>
        @endif
      </table>
    @endforeach
  @endif

  <p style="margin:44px 0 0;font-size:12px;line-height:1.6;color:#9ca3af;">{{ $t('footer') }}</p>
@endsection
