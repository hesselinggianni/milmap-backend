@extends('emails.layout-minimal')
@section('title', __('mail.new_user_registered.page_title'))
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">
    {{ __('mail.new_user_registered.title') }}
  </h1>
  <p style="margin:0 0 36px;">{{ __('mail.new_user_registered.subtitle') }}</p>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5e7eb;">
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_user_registered.email') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ $user->email }}</td>
</tr>
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_user_registered.id') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;font-family:monospace;">{{ $user->id }}</td>
</tr>
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_user_registered.date') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ now() }}</td>
</tr>
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_user_registered.registered_via') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;word-break:break-all;">{{ !empty($sourceUrl) ? $sourceUrl : __('mail.new_user_registered.unknown') }}</td>
</tr>
@if(!empty($referrer))
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_user_registered.referrer') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;word-break:break-all;">{{ $referrer ?? '' }}</td>
</tr>
@endif
@if(!empty($useCase))
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_user_registered.use_case') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ $useCase ?? '' }}</td>
</tr>
@endif
@if(!empty($interests))
<tr>
  <td style="padding:12px 0;width:34%;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;">{{ __('mail.new_user_registered.interests') }}</td>
  <td style="padding:12px 0;font-size:15px;font-weight:600;color:#01163d;border-bottom:1px solid #e5e7eb;">{{ implode(', ', $interests ?? []) }}</td>
</tr>
@endif
  </table>
@endsection
