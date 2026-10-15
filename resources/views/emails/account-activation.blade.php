@extends('emails.layout-minimal')
@section('title', __('mail.account_activation.title') . ' — Milmap')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">{{ __('mail.account_activation.title') }}</h1>
  @if($firstName)<p style="margin:0 0 20px;">{{ __('mail.account_activation.greeting', ['name' => $firstName]) }}</p>@endif
  <p style="margin:0 0 20px;">{!! __('mail.account_activation.intro', ['plan' => '<strong style="color:#01163d;">' . 'Milmap ' . e($planLabel) . '</strong>']) !!}</p>
  <p style="margin:0 0 20px;"><strong style="color:#01163d;">{{ __('mail.account_activation.plan_badge', ['plan' => $planLabel]) }}</strong></p>
  <p style="margin:0 0 20px;">{!! __('mail.account_activation.step_intro', ['duration' => '<strong style="color:#01163d;">' . __('mail.account_activation.duration') . '</strong>']) !!}</p>
  <p style="margin:0 0 20px;"><a href="{{ $setupUrl }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">{{ __('mail.account_activation.cta') }}</a></p>
  <p style="margin:0 0 20px;font-size:14px;color:#6b7280;word-break:break-all;">{{ __('mail.account_activation.fallback') }}<br><span style="color:#01163d;">{{ $setupUrl }}</span></p>
  <p style="margin:32px 0 8px;font-weight:700;color:#01163d;">{{ __('mail.account_activation.next_label') }}</p>
  <p style="margin:0 0 32px;line-height:1.9;">
    @foreach([__('mail.account_activation.next_1'), __('mail.account_activation.next_2'), __('mail.account_activation.next_3')] as $t)
    &bull; {{ $t }}<br>
    @endforeach
  </p>
  <p style="margin:0;">{{ __('mail.account_activation.footer') }} <a href="mailto:app@milmap.nl" style="color:#01163d;">app@milmap.nl</a></p>
@endsection
