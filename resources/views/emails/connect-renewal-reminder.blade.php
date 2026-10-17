@extends('emails.layout-minimal')
@section('title', 'Licentie verlengen')
@section('body')
  <h1 style="margin:0 0 40px;font-size:34px;line-height:1.2;font-weight:800;color:#01163d;letter-spacing:-0.02em;">Je licentie verloopt over {{ $daysLeft }} {{ $daysLeft === 1 ? 'dag' : 'dagen' }}</h1>
  <p style="margin:0 0 20px;">De MilMap Connect-licentie van <strong style="color:#01163d;">{{ $organizationName }}</strong> loopt af op <strong style="color:#01163d;">{{ \Illuminate\Support\Carbon::parse($endsAt)->locale('nl')->translatedFormat('j F Y') }}</strong>.</p>
  <p style="margin:0 0 28px;">Daarna hebben de {{ $seats }} accounts en apparaten geen licentie meer en kunnen ze Connect niet meer gebruiken. Verleng op tijd, dan blijft alles zonder onderbreking werken.</p>
  <p style="margin:0 0 20px;"><a href="{{ $renewUrl }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;background:#01163d;text-decoration:none;border-radius:8px;">Licentie verlengen</a></p>
  <p style="margin:0;">In het Connect-portaal zie je onder Licenties welke apparaten nu geen licentie hebben.</p>
@endsection
