@extends('emails.layout')

@section('title', 'Bevestigingscode account verwijderen - Milmap')

@section('body')
  <h2 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#f8fafc;">
    Bevestig het verwijderen van je account
  </h2>
  <p style="margin:0 0 24px;font-size:14px;color:#cbd5e1;line-height:1.6;">
    Je hebt gevraagd om je MilMap-account te verwijderen. Vul onderstaande code in de app in om door te gaan.
    De code is {{ $minutes }} minuten geldig.
  </p>

  <div style="margin:0 0 24px;padding:18px;text-align:center;background:#0f172a;border:1px solid #1e293b;border-radius:12px;">
    <span style="font-size:30px;letter-spacing:8px;font-weight:700;color:#f8fafc;">{{ $code }}</span>
  </div>

  <p style="margin:0;font-size:13px;color:#94a3b8;line-height:1.6;">
    Heb je dit niet zelf gedaan? Dan hoef je niets te doen — zonder deze code gebeurt er niets.
    Verander voor de zekerheid wel je wachtwoord.
  </p>
@endsection
