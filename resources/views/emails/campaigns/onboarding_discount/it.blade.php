@extends('emails.layout')

@section('title', 'Sconto temporaneo')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#22c55e;">
    Offerta a tempo limitato · Giorno 14
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    {{ $name ? $name . ', passa' : 'Passa' }} ora — con uno sconto
  </h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
    <tr><td align="center">
      <img src="https://milmap.nl/email/onboarding/onboarding_discount.png" alt="Offerta temporanea su MilMap Premium" width="520" style="width:100%;max-width:520px;height:auto;border:0;display:block;outline:none;text-decoration:none;" />
    </td></tr>
  </table>

  <p style="margin:0 0 16px;">
    Ancora indeciso? Ti diamo una spinta in più: sottoscrivi ora un abbonamento MilMap e
    approfitta della nostra offerta a tempo limitato. Così lavori senza limitazioni — sul
    campo e in ufficio.
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li>Mappe illimitate &amp; offline</li>
    <li>Missioni, team e comunicazioni crittografate</li>
    <li>Esportazione delle mappe di percorso e analisi del terreno</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#22c55e;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/checkout/pro_yearly' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Scopri l'offerta
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer', ['unsubLine' => 'Ricevi questa email perché ti sei registrato su MilMap.', 'unsubLabel' => 'Annulla l\'iscrizione a queste email'])
@endsection
