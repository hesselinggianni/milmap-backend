@extends('emails.layout')

@section('title', 'Passa a Premium')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#fbbf24;">
    La tua prova sta per scadere · Giorno 7
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', mantieni' : 'Mantieni' }} tutte le tue funzioni
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_upgrade.png" alt="Schermata di aggiornamento a MilMap Premium" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    I tuoi 7 giorni gratuiti con accesso completo stanno per finire. Senza abbonamento
    potrai continuare gratuitamente con le funzioni di base (massimo 5 mappe), ma perderai
    tra l'altro:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Missioni, chat e il MEDEVAC 9-liner</li>
    <li>Mappe illimitate &amp; offline + integrazione meteo</li>
    <li>Esportazione delle mappe di percorso, team e condivisione della posizione live</li>
  </ul>

  <p style="margin:0 0 22px;">
    A partire da <strong>4,99&nbsp;€/mese</strong> mantieni tutto. Disdici quando vuoi.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_monthly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Passa a Premium
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Ricevi questa email perché ti sei registrato su MilMap.', 'unsubLabel' => 'Annulla l\'iscrizione a queste email'])
@endsection
