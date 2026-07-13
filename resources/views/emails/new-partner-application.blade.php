@extends('emails.layout')
@section('title', 'Nieuwe partneraanmelding')
@section('body')

  <h1 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;">
    Nieuwe partneraanmelding
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 20px;background:#1a2433;border:1px solid #2a3a52;border-radius:10px;">
    <tr><td style="padding:16px 18px;">
      <p style="margin:0 0 6px;font-size:13px;color:#94a3b8;">Naam / bedrijf</p>
      <p style="margin:0 0 14px;font-size:14px;color:#f8fafc;font-weight:600;">
        {{ trim(($partner->user->first_name ?? '') . ' ' . ($partner->user->last_name ?? '')) ?: '—' }}
        {{ $partner->company_name ? ' — ' . $partner->company_name : '' }}
      </p>
      <p style="margin:0 0 6px;font-size:13px;color:#94a3b8;">E-mail</p>
      <p style="margin:0 0 14px;font-size:14px;color:#f8fafc;">{{ $partner->user->email }}</p>
      @if($partner->website)
      <p style="margin:0 0 6px;font-size:13px;color:#94a3b8;">Website</p>
      <p style="margin:0 0 14px;font-size:14px;color:#f8fafc;">{{ $partner->website }}</p>
      @endif
      <p style="margin:0 0 6px;font-size:13px;color:#94a3b8;">Motivatie</p>
      <p style="margin:0;font-size:14px;line-height:1.6;color:#cbd5e1;">{{ $partner->description }}</p>
    </td></tr>
  </table>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="border-radius:10px;background:#2b7fff;">
        <a href="https://admin.milmap.nl/partners"
           style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
          Beoordelen in admin
        </a>
      </td>
    </tr>
  </table>

@endsection
