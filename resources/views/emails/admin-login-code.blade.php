@extends('emails.layout')

@section('title', 'Admin Login Code - MilMap')

@section('body')
  <h2 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#1a1a1a;">
    🔐 Admin Login Code
  </h2>
  <p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.6;">
    Je hebt een inlogcode aangevraagd voor het MilMap admin dashboard. Gebruik de code hieronder om in te loggen:
  </p>

  <!-- Code box -->
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

  <!-- Info box -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="margin:0 0 20px;">
    <tr>
      <td style="background-color:#f0f5ff;border-left:4px solid #2b7fff;border-radius:4px;padding:14px 16px;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#1a1a1a;">
          ⏱️ Deze code vervalt over {{ $expiryMinutes }} minuten
        </p>
        <p style="margin:0;font-size:13px;color:#555;line-height:1.5;">
          Voer deze code in op het login-scherm. De code kan maar één keer gebruikt worden.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.6;">
    Als je deze code niet aangevraagd hebt, negeer deze email dan.
  </p>

  <!-- Warning box -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="background-color:#fff8e1;border-left:4px solid #f59e0b;border-radius:4px;padding:14px 16px;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#92400e;">
          ⚠️ VEILIGHEID
        </p>
        <p style="margin:0;font-size:13px;color:#78350f;line-height:1.5;">
          Deel deze code met niemand. MilMap medewerkers zullen je nooit om deze code vragen.
        </p>
      </td>
    </tr>
  </table>
@endsection
