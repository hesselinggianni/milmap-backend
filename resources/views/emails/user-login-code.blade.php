@extends('emails.layout')

@section('title', 'Je inlogcode — Milmap')

@section('body')
  <h2 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#f8fafc;">
    Je inlogcode
  </h2>
  <p style="margin:0 0 24px;font-size:14px;color:#cbd5e1;line-height:1.6;">
    Gebruik de code hieronder om in te loggen bij Milmap:
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 24px;">
    <tr>
      <td align="center"
          style="background:linear-gradient(135deg,#2b7fff 0%,#1a6ae6 100%);
                 border-radius:10px;padding:28px 20px;">
        <span style="font-size:42px;font-weight:700;color:#ffffff;letter-spacing:10px;
                     font-family:'Courier New',monospace;">
          {{ $code }}
        </span>
      </td>
    </tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 20px;">
    <tr>
      <td style="background-color:#1a2433;border-left:4px solid #2b7fff;border-radius:4px;padding:14px 16px;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#f8fafc;">
          Deze code vervalt over {{ $expiryMinutes }} minuten
        </p>
        <p style="margin:0;font-size:13px;color:#cbd5e1;line-height:1.5;">
          Voer 'm in op het inlogscherm. De code werkt maar één keer.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:13px;color:#7e8a9c;line-height:1.6;">
    Niet zelf aangevraagd? Dan kun je deze e-mail negeren — er verandert niets aan je account.
  </p>
@endsection
