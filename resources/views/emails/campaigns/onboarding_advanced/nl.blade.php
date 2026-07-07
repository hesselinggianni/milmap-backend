@extends('emails.layout')

@section('title', 'Geavanceerde functies')

@section('body')
  <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#2b7fff;">
    Ga verder · Dag 3
  </p>
  <h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:700;color:#ffffff;">
    De kracht van MilMap
  </h1>

  <p style="margin:0 0 16px;">
    {{ $name ? $name . ', je' : 'Je' }} kunt veel meer dan kaarten tekenen. Deze functies
    maken echt het verschil in het veld:
  </p>

  <ul style="margin:0 0 22px;padding-left:18px;color:#cbd5e1;font-size:14px;line-height:1.8;">
    <li><strong>Missies</strong> — plan, brief en werk samen volgens SMEAC.</li>
    <li><strong>MEDEVAC 9-liner</strong> — snel en gestructureerd aanvragen.</li>
    <li><strong>Terreinanalyse</strong> — hoogteprofiel en zichtlijnen.</li>
    <li><strong>Offline kaarten</strong> — werk door zonder verbinding.</li>
  </ul>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
    <tr>
      <td style="background-color:#2b7fff;border-radius:12px;">
        <a href="{{ ($appUrl ?? 'https://app.milmap.nl') . '/maps' }}" target="_blank"
           style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
          Probeer het uit
        </a>
      </td>
    </tr>
  </table>

  @include('emails.partials.unsubscribe-footer')
@endsection
