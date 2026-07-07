@extends('emails.layout')

@section('title', $title ?? 'MilMap')

@section('body')
  @foreach (($blocks ?? []) as $b)
    @php $type = $b['type'] ?? ''; @endphp

    @switch($type)
      @case('eyebrow')
        @php $ebColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($b['color'] ?? '')) ? $b['color'] : '#2b7fff'; @endphp
        <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:{{ $ebColor }};">
          {{ $b['text'] ?? '' }}
        </p>
        @break

      @case('heading')
        <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
          {{ $b['text'] ?? '' }}
        </h1>
        @break

      @case('paragraph')
        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#cbd5e1;">
          {!! nl2br(e($b['text'] ?? '')) !!}
        </p>
        @break

      @case('image')
        @php
          $imgUrl  = preg_match('#^https?://#i', (string)($b['url'] ?? '')) ? $b['url'] : '';
          $imgHref = preg_match('#^https?://#i', (string)($b['href'] ?? '')) ? $b['href'] : '';
        @endphp
        @if ($imgUrl)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
          <tr><td align="center">
            @if ($imgHref)<a href="{{ $imgHref }}" target="_blank" style="text-decoration:none;">@endif
            <img src="{{ $imgUrl }}" alt="{{ $b['alt'] ?? '' }}" width="520"
                 style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;border-radius:{{ isset($b['radius']) ? (int)$b['radius'] : 12 }}px;" />
            @if ($imgHref)</a>@endif
          </td></tr>
        </table>
        @endif
        @break

      @case('button')
        @php
          $btnUrl   = preg_match('#^https?://#i', (string)($b['url'] ?? '')) ? $b['url'] : '#';
          $btnColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($b['color'] ?? '')) ? $b['color'] : '#2b7fff';
          $btnAlign = in_array(($b['align'] ?? ''), ['left','center','right'], true) ? $b['align'] : 'center';
        @endphp
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="{{ $btnAlign }}" style="margin:4px auto 22px;">
          <tr>
            <td style="background-color:{{ $btnColor }};border-radius:12px;">
              <a href="{{ $btnUrl }}" target="_blank"
                 style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                {{ $b['label'] ?? 'Meer info' }}
              </a>
            </td>
          </tr>
        </table>
        @break

      @case('list')
        <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
          @foreach (($b['items'] ?? []) as $item)
            <li>{{ $item }}</li>
          @endforeach
        </ul>
        @break

      @case('divider')
        <div style="height:1px;line-height:1px;font-size:0;background:#1e293b;margin:20px 0;">&nbsp;</div>
        @break

      @case('spacer')
        <div style="height:{{ isset($b['size']) ? (int)$b['size'] : 16 }}px;line-height:1px;font-size:0;">&nbsp;</div>
        @break
    @endswitch
  @endforeach

  @include('emails.partials.unsubscribe-footer')
@endsection
