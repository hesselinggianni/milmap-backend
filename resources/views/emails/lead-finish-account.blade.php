@php
    // E-mail-veilige helpers. Alle styling inline; tabellen voor Outlook.
    // Zelfde kleurenpalet + kaart-look als app-download.blade.php.
    $blue  = '#2b7fff';
    $navy  = '#01163d';
    $ink   = '#1d1d1f';   // Apple "ink"
    $muted = '#6e6e73';   // Apple secondary text
    $line  = '#e5e5ea';
@endphp
<!DOCTYPE html>
<html lang="{{ $localeCode }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{{ $lang['title'] }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f7;-webkit-font-smoothing:antialiased;font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{{ $ink }};">

  <!-- Preheader (verborgen) -->
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
    {{ $lang['preheader'] }}
  </div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f5f7;">
    <tr>
      <td align="center" style="padding:32px 16px;">

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;">

          <!-- Logo -->
          <tr>
            <td align="center" style="padding:8px 0 24px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="124" height="21" viewBox="0 0 944 160" fill="none" aria-label="MilMap" style="display:inline-block;">
                <path d="M861.02 107.328V78.3735H895.961V28.8458H861.02V0H915.01L943.856 28.8458V78.3735L915.01 107.328H861.02ZM806.158 144.447V0H853.4V144.447H806.158Z" fill="{{ $navy }}"/>
                <path d="M744.656 144.447L738.561 128.663H691.319L701.333 100.035H727.458L688.815 0H735.731L793.313 144.447H744.656ZM631.995 144.447L683.59 6.53112L704.925 63.8961L678.474 144.447H631.995Z" fill="{{ $navy }}"/>
                <path d="M529.239 144.447L443.572 0H494.733L533.484 64.2227L563.963 11.9737V84.6869L529.239 144.447ZM571.038 144.447V0H619.151V144.447H571.038ZM443.463 144.447V13.3888L488.419 88.8233V144.447H443.463Z" fill="{{ $navy }}"/>
                <path d="M298.472 144.447V0H346.585V144.447H298.472ZM354.204 144.447V115.601H378.043V97.3137H421.693V144.447H354.204Z" fill="{{ $navy }}"/>
                <path d="M198.111 144.447V113.642H212.588V30.6963H198.111V0H275.178V30.6963H260.701V113.642H275.178V144.447H198.111Z" fill="{{ $navy }}"/>
                <path d="M85.7754 144.447L0.108852 0H51.2693L90.0206 64.2227L120.499 11.9737V84.6869L85.7754 144.447ZM127.575 144.447V0H175.687V144.447H127.575ZM0 144.447V13.3888L44.9559 88.8233V144.447H0Z" fill="{{ $navy }}"/>
              </svg>
            </td>
          </tr>

          <!-- Card -->
          <tr>
            <td style="background-color:#ffffff;border-radius:22px;box-shadow:0 6px 28px rgba(0,0,0,0.06);overflow:hidden;">

              <!-- Hero -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td align="center" style="padding:40px 32px 8px;">
                    <p style="margin:0 0 10px;font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:{{ $blue }};">
                      {{ $lang['eyebrow'] }}
                    </p>
                    <h1 style="margin:0 0 12px;font-size:26px;line-height:1.2;font-weight:700;letter-spacing:-0.02em;color:{{ $ink }};">
                      {{ $lang['title'] }}
                    </h1>
                    <p style="margin:0;font-size:16px;line-height:1.5;color:{{ $muted }};max-width:380px;">
                      {{ $lang['intro'] }}
                    </p>
                  </td>
                </tr>
              </table>

              <!-- Coupon -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="padding:26px 24px 8px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                           style="background-color:#f0f6ff;border:2px dashed {{ $blue }};border-radius:18px;">
                      <tr>
                        <td align="center" style="padding:24px 22px;">
                          <p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:{{ $blue }};">
                            {{ $lang['codeLabel'] }}
                          </p>
                          <p style="margin:0 0 14px;font-size:22px;font-weight:700;letter-spacing:-0.01em;color:{{ $ink }};">
                            {{ $lang['discount'] }}
                          </p>

                          <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                            <tr>
                              <td style="background-color:#ffffff;border:1px solid {{ $line }};border-radius:12px;padding:13px 22px;">
                                <span style="font-family:'SF Mono',Menlo,Consolas,monospace;font-size:21px;font-weight:700;letter-spacing:0.06em;color:{{ $navy }};">{{ $couponCode }}</span>
                              </td>
                            </tr>
                          </table>

                          <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin-top:18px;">
                            <tr>
                              <td style="background-color:{{ $blue }};border-radius:12px;">
                                <a href="{{ $continueUrl }}" target="_blank"
                                   style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                                  {{ $lang['button'] }}
                                </a>
                              </td>
                            </tr>
                          </table>

                          <p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:{{ $muted }};">
                            {{ $lang['oneTime'] }}
                            @if($couponExpiresLabel) {{ str_replace(':date', $couponExpiresLabel, $lang['validUntil']) }} @endif
                          </p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- Outro -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="padding:14px 32px 34px;">
                    <p style="margin:0;font-size:13px;line-height:1.6;color:{{ $muted }};text-align:center;">
                      {{ $lang['questions'] }}
                      <a href="mailto:support@milmap.nl" style="color:{{ $blue }};text-decoration:none;">support@milmap.nl</a>.
                    </p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td align="center" style="padding:22px 24px 8px;">
              <p style="margin:0 0 4px;font-size:12px;color:{{ $muted }};">MilMap &middot; milmap.nl</p>
              <p style="margin:0;font-size:11px;color:#a1a1a6;line-height:1.5;">
                {{ $lang['disclaimer'] }}
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
