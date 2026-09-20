{{-- Tracking-pixel (open-tracking) + afmeldlink. $pixelUrl en $unsubscribeUrl worden
     door CampaignMail meegegeven; bij een testmail/preview kunnen ze leeg zijn. --}}
@isset($pixelUrl)
  @if($pixelUrl)
    <img src="{{ $pixelUrl }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0;overflow:hidden;" />
  @endif
@endisset

@include('emails.partials.signature')

@isset($unsubscribeUrl)
  @if($unsubscribeUrl)
    <p style="margin:28px 0 0;font-size:11px;color:#64748b;line-height:1.6;text-align:center;">
      {{ $unsubLine ?? __('mail.unsubscribe.line') }}<br>
      <a href="{{ $unsubscribeUrl }}" style="color:#64748b;text-decoration:underline;">{{ $unsubLabel ?? __('mail.unsubscribe.label') }}</a>
    </p>
  @endif
@endisset
