@extends('emails.layout')

@section('title', 'Crea la tua prima mappa')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Iniziamo · Giorno 1
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', crea' : 'Crea' }} la tua prima mappa
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_first_map.png" alt="Creazione di una nuova mappa con coordinate MGRS" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    In pochi minuti avrai pronta la tua prima mappa operativa. Ecco come fare:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Apri MilMap e clicca su <strong>Nuova mappa</strong>.</li>
    <li>Cerca la tua area e inserisci i punti con le coordinate MGRS.</li>
    <li>Importa le tracce GPX/KML esistenti, se ne hai.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Crea una mappa
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Ricevi questa email perché ti sei registrato su MilMap.', 'unsubLabel' => 'Annulla l\'iscrizione a queste email'])
@endsection
