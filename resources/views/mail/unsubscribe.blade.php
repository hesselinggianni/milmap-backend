<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Afmelden — Milmap</title>
  <style>
    body{margin:0;background:#0a0f1a;color:#cbd5e1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
    .wrap{max-width:480px;margin:0 auto;padding:56px 18px;}
    .card{background:#0f172a;border:1px solid #1e293b;border-radius:16px;padding:32px 28px;box-shadow:0 8px 40px rgba(0,0,0,.5);}
    h1{margin:0 0 12px;font-size:22px;color:#fff;}
    p{font-size:14px;line-height:1.6;color:#94a3b8;}
    .email{color:#fff;font-weight:600;}
    .btn{display:inline-block;width:100%;box-sizing:border-box;text-align:center;padding:12px 18px;border:0;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;margin-top:10px;}
    .btn--primary{background:#ef4444;color:#fff;}
    .btn--ghost{background:transparent;border:1px solid #334155;color:#cbd5e1;}
    .btn--green{background:#10b981;color:#fff;}
    .muted{font-size:12px;color:#64748b;text-align:center;margin-top:20px;}
    form{margin:0;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      @if(!empty($invalid))
        <h1>Link niet (meer) geldig</h1>
        <p>Deze afmeldlink is ongeldig of verlopen. Je kunt je voorkeuren ook beheren in je
           MilMap-account onder <strong>Instellingen → Meldingen</strong>.</p>
      @elseif(!empty($done))
        @if(!empty($resubscribed))
          <h1>Je bent weer aangemeld ✓</h1>
          <p><span class="email">{{ $email }}</span> ontvangt weer e-mails over
             <strong>{{ $categoryName }}</strong>.</p>
        @else
          <h1>Je bent afgemeld ✓</h1>
          <p>We sturen <span class="email">{{ $email }}</span> geen e-mails meer over
             <strong>{{ $categoryName }}</strong>.</p>
          <form method="POST" action="/api/v1/m/u/{{ $token }}">
            <input type="hidden" name="scope" value="resubscribe">
            <button class="btn btn--green" type="submit">Toch weer aanmelden</button>
          </form>
        @endif
      @else
        <h1>Afmelden voor e-mails</h1>
        <p><span class="email">{{ $email }}</span> is aangemeld voor e-mails over
           <strong>{{ $categoryName }}</strong>. Wil je je hiervoor afmelden?</p>

        <form method="POST" action="/api/v1/m/u/{{ $token }}">
          <input type="hidden" name="scope" value="category">
          <button class="btn btn--primary" type="submit">Afmelden voor "{{ $categoryName }}"</button>
        </form>
        <form method="POST" action="/api/v1/m/u/{{ $token }}">
          <input type="hidden" name="scope" value="all">
          <button class="btn btn--ghost" type="submit">Afmelden voor álle marketing-e-mails</button>
        </form>
      @endif
    </div>
    <p class="muted">Milmap · milmap.nl</p>
  </div>
</body>
</html>
