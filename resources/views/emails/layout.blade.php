<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark">
  <meta name="supported-color-schemes" content="dark">
  <title>@yield('title', 'MilMap')</title>
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
                    <span style="display:inline-block;width:28px;height:28px;background-color:#0b2a52;
                                 border:1px solid #21457d;border-radius:8px;text-align:center;line-height:30px;
                                 vertical-align:middle;margin-right:10px;">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                           fill="#2b7fff" style="display:inline-block;vertical-align:middle;">
                        <path d="M14.1 5.6a2 2 0 0 0 1.8 0l3.6-1.9V17l-4.5 2.3a2 2 0 0 1-1.8 0L8.6 17a2 2 0 0 0-1.8 0L3 18.9V6.6l4.5-2.3a2 2 0 0 1 1.8 0z"/>
                      </svg>
                    </span>
                    <span style="font-size:16px;font-weight:800;letter-spacing:-0.01em;vertical-align:middle;color:#ffffff;">
                      Mil<span style="color:#2b7fff;">Map</span>
                    </span>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="font-size:9.5px;font-weight:700;letter-spacing:0.12em;
                                 color:#64748b;text-transform:uppercase;">
                      Training Platform
                    </span>
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
              <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#94a3b8;letter-spacing:0.02em;">
                Mil<span style="color:#2b7fff;">Map</span>
              </p>
              <p style="margin:0;font-size:11px;color:#64748b;line-height:1.5;">
                Training Platform &nbsp;&middot;&nbsp; Alleen voor geautoriseerd trainingsgebruik
              </p>
            </td>
          </tr>

        </table>

        <p style="margin:16px 0 0;font-size:11px;color:#3f4a5a;">&copy; {{ date('Y') }} MilMap</p>

      </td>
    </tr>
  </table>

</body>
</html>
