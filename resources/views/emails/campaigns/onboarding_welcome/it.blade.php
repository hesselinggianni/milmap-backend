@extends('emails.layout')

@section('title', 'Benvenuto su MilMap')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Benvenuto a bordo
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? 'Benvenuto, ' . $name : 'Benvenuto' }} su MilMap
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_welcome.png" alt="App MilMap con mappa e schermata di benvenuto" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Il tuo account è pronto. Nei prossimi <strong>7 giorni</strong> avrai accesso completo a
    tutte le funzioni — mappe, pianificazione dei percorsi, missioni, analisi del terreno e
    comunicazioni di squadra crittografate. Dopodiché potrai continuare gratuitamente con le
    funzioni di base.
  </p>

  <p style="margin:0 0 22px;">
    Un consiglio: apri l'app e crea la tua prima mappa. Domani ti invieremo una breve guida
    per iniziare rapidamente.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Apri MilMap
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
