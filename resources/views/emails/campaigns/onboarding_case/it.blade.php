@extends('emails.layout')

@section('title', 'MilMap sul campo')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Caso cliente · Giorno 5
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    Ecco come le unità usano MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_case.png" alt="Mappa del percorso con checkpoint in MilMap" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Un'unità da ricognizione ha utilizzato MilMap durante un'esercitazione in montagna per
    pianificare i percorsi, portare con sé le mappe di percorso in PDF e navigare offline
    senza copertura.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Mappe di percorso create e stampate con coordinate MGRS.</li>
    <li>Analisi del terreno di valichi montani e accampamenti.</li>
    <li>Mappe offline per l'uso senza connessione.</li>
    <li>Modulo Missioni per la pianificazione dell'O-group.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($siteUrl ?? 'https://milmap.nl') }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Leggi altre storie
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Ricevi questa email perché ti sei registrato su MilMap.', 'unsubLabel' => 'Annulla l\'iscrizione a queste email'])
@endsection
