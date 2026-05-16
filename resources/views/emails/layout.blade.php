<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title', 'MilMap')</title>
</head>
<body style="margin:0;padding:0;background-color:#eef1f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="background-color:#eef1f6;padding:40px 16px;">
    <tr>
      <td align="center">

        <!-- Card -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:520px;background-color:#ffffff;border:1px solid #dde2ea;border-radius:12px;overflow:hidden;
                      box-shadow:0 4px 24px rgba(0,0,0,0.07);">

          <!-- Header -->
          <tr>
            <td style="background-color:#2b7fff;padding:18px 24px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td>
                    <span style="font-size:15px;font-weight:700;color:#ffffff;letter-spacing:-0.01em;">
                      MilMap
                    </span>
                    <span style="font-size:11px;color:rgba(255,255,255,0.65);margin-left:8px;font-weight:500;letter-spacing:0.04em;text-transform:uppercase;">
                     
                    </span>
                  </td>
                  <td align="right">
                    <span style="font-size:9.5px;font-weight:600;letter-spacing:0.1em;
                                 color:rgba(255,255,255,0.5);text-transform:uppercase;">
                      
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px 28px 24px;">
              @yield('body')
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f8f9fb;border-top:1px solid #eaecf0;padding:14px 24px;
                       text-align:center;font-size:11px;color:#9aa3b0;letter-spacing:0.02em;">
              MilMap Training Platform &nbsp;&middot;&nbsp; Alleen voor geautoriseerd trainingsgebruik
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>

</body>
</html>
