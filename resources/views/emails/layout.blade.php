<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark">
  <meta name="supported-color-schemes" content="dark">
  <title>@yield('title', 'Milmap')</title>
</head>
<body style="margin:0;padding:0;background-color:#0a0f1a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#cbd5e1;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="background-color:#0a0f1a;padding:40px 16px;">
    <tr>
      <td align="center">

        <!-- Card -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:540px;background-color:#0f172a;border:1px solid #1e293b;border-radius:16px;
                      overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.5);">

          <!-- Header -->
          <tr>
            <td style="background-color:#01163d;border-bottom:1px solid #1e293b;padding:18px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="vertical-align:middle;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="132" height="22" viewBox="0 0 944 160"
                         fill="none" style="display:inline-block;vertical-align:middle;" aria-label="Milmap">
                      <path d="M861.02 107.328V78.3735H895.961V28.8458H861.02V0H915.01L943.856 28.8458V78.3735L915.01 107.328H861.02ZM806.158 144.447V0H853.4V144.447H806.158Z" fill="#ffffff"/>
                      <path d="M744.656 144.447L738.561 128.663H691.319L701.333 100.035H727.458L688.815 0H735.731L793.313 144.447H744.656ZM631.995 144.447L683.59 6.53112L704.925 63.8961L678.474 144.447H631.995Z" fill="#ffffff"/>
                      <path d="M529.239 144.447L443.572 0H494.733L533.484 64.2227L563.963 11.9737V84.6869L529.239 144.447ZM571.038 144.447V0H619.151V144.447H571.038ZM443.463 144.447V13.3888L488.419 88.8233V144.447H443.463Z" fill="#ffffff"/>
                      <path d="M298.472 144.447V0H346.585V144.447H298.472ZM354.204 144.447V115.601H378.043V97.3137H421.693V144.447H354.204Z" fill="#ffffff"/>
                      <path d="M198.111 144.447V113.642H212.588V30.6963H198.111V0H275.178V30.6963H260.701V113.642H275.178V144.447H198.111Z" fill="#ffffff"/>
                      <path d="M85.7754 144.447L0.108852 0H51.2693L90.0206 64.2227L120.499 11.9737V84.6869L85.7754 144.447ZM127.575 144.447V0H175.687V144.447H127.575ZM0 144.447V13.3888L44.9559 88.8233V144.447H0Z" fill="#ffffff"/>
                    </svg>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px 28px 26px;color:#cbd5e1;font-size:14px;line-height:1.6;">
              @yield('body')
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#01163d;border-top:1px solid #1e293b;padding:18px 24px;text-align:center;">
              <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#cbd5e1;letter-spacing:0.02em;">
                Milmap
              </p>
              <p style="margin:0;font-size:11px;color:#64748b;line-height:1.5;">
                Alleen voor geautoriseerd trainingsgebruik
              </p>
            </td>
          </tr>

        </table>

        <p style="margin:16px 0 0;font-size:11px;color:#3f4a5a;">&copy; {{ date('Y') }} Milmap</p>

      </td>
    </tr>
  </table>

</body>
</html>
