@extends('emails.layout')

@section('title', 'Funzioni avanzate')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Vai oltre · Giorno 3
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    La potenza di MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_advanced.png" alt="Briefing di missione e profilo del terreno in MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . ', puoi' : 'Puoi' }} fare molto più che disegnare mappe. Queste funzioni
    fanno davvero la differenza sul campo:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>Missioni</strong> — pianifica, fai il briefing e collabora seguendo lo SMEAC.</li>
    <li><strong>MEDEVAC 9-liner</strong> — richieste rapide e strutturate.</li>
    <li><strong>Analisi del terreno</strong> — profilo altimetrico e linee di visuale.</li>
    <li><strong>Mappe offline</strong> — continua a lavorare senza connessione.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Provalo subito
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Ricevi questa email perché ti sei registrato su MilMap.', 'unsubLabel' => 'Annulla l\'iscrizione a queste email'])
@endsection
