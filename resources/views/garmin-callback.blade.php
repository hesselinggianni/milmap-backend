<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Garmin koppelen — Milmap</title>
  <style>
    body{margin:0;background:#0a0f1a;color:#cbd5e1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
    .wrap{max-width:480px;margin:0 auto;padding:56px 18px;}
    .card{background:#0f172a;border:1px solid #1e293b;border-radius:16px;padding:32px 28px;box-shadow:0 8px 40px rgba(0,0,0,.5);text-align:center;}
    h1{margin:0 0 12px;font-size:22px;color:#fff;}
    p{font-size:14px;line-height:1.6;color:#94a3b8;}
    .btn{display:inline-block;box-sizing:border-box;text-align:center;padding:12px 24px;border:0;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;margin-top:16px;text-decoration:none;}
    .btn--primary{background:#10b981;color:#fff;}
    .muted{font-size:12px;color:#64748b;text-align:center;margin-top:20px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      @if(($status ?? 'error') === 'success')
        <h1>Garmin gekoppeld ✓</h1>
        <p>Je Garmin Connect-account is gekoppeld aan MilMap. Je kunt nu terug naar de app.</p>
      @else
        <h1>Koppelen mislukt</h1>
        <p>Er ging iets mis bij het koppelen van je Garmin-account. Probeer het opnieuw vanuit MilMap.</p>
      @endif
      <a class="btn btn--primary" href="milmap://garmin/callback?status={{ $status ?? 'error' }}">Terug naar MilMap</a>
    </div>
    <p class="muted">Milmap · milmap.nl</p>
  </div>
  <script>
    // Sommige mobiele browsers staan een onbeheerde redirect naar een custom
    // scheme toe zonder gebaar; probeer het automatisch, met de knop hierboven
    // als zichtbare fallback wanneer dat geblokkeerd wordt.
    window.location.href = "milmap://garmin/callback?status={{ $status ?? 'error' }}";
  </script>
</body>
</html>
