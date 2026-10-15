<!DOCTYPE html>
<html lang="{{ $locale ?? 'nl' }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,700;12..96,800&display=swap" rel="stylesheet">
  <style>body,td,p,h1,strong{font-family:'Bricolage Grotesque',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif !important;}</style>
  <title>@yield('title', 'Milmap')</title>
</head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:'Bricolage Grotesque',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#4b5563;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="background-color:#ffffff;border-top:1px solid #e5e7eb;">
    <tr>
      <td align="center" style="padding:48px 24px 56px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
          <tr>
            <td style="padding:0 0 56px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="150" height="25" viewBox="0 0 944 160"
                         fill="none" style="display:inline-block;vertical-align:middle;" aria-label="Milmap">
                      <path d="M861.02 107.328V78.3735H895.961V28.8458H861.02V0H915.01L943.856 28.8458V78.3735L915.01 107.328H861.02ZM806.158 144.447V0H853.4V144.447H806.158Z" fill="#01163d"/>
                      <path d="M744.656 144.447L738.561 128.663H691.319L701.333 100.035H727.458L688.815 0H735.731L793.313 144.447H744.656ZM631.995 144.447L683.59 6.53112L704.925 63.8961L678.474 144.447H631.995Z" fill="#01163d"/>
                      <path d="M529.239 144.447L443.572 0H494.733L533.484 64.2227L563.963 11.9737V84.6869L529.239 144.447ZM571.038 144.447V0H619.151V144.447H571.038ZM443.463 144.447V13.3888L488.419 88.8233V144.447H443.463Z" fill="#01163d"/>
                      <path d="M298.472 144.447V0H346.585V144.447H298.472ZM354.204 144.447V115.601H378.043V97.3137H421.693V144.447H354.204Z" fill="#01163d"/>
                      <path d="M198.111 144.447V113.642H212.588V30.6963H198.111V0H275.178V30.6963H260.701V113.642H275.178V144.447H198.111Z" fill="#01163d"/>
                      <path d="M85.7754 144.447L0.108852 0H51.2693L90.0206 64.2227L120.499 11.9737V84.6869L85.7754 144.447ZM127.575 144.447V0H175.687V144.447H127.575ZM0 144.447V13.3888L44.9559 88.8233V144.447H0Z" fill="#01163d"/>
                    </svg>
            </td>
          </tr>
          <tr>
            <td style="font-size:17px;line-height:1.7;color:#4b5563;">
              @yield('body')
            </td>
          </tr>
          <tr>
            <td style="padding-top:48px;">
              <div style="height:1px;background:#e5e7eb;"></div>
              <p style="margin:24px 0 0;font-size:14px;font-weight:700;color:#01163d;">&copy; {{ date('Y') }} Milmap</p>
              <p style="margin:12px 0 0;font-size:13px;color:#6b7280;">{{ __('mail.minimal_footer') }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
