{{--
  SYRTAK | سيرتك — transactional email shell
  Brand: government forest green, golden wheat, deep umber (alerts only)
  Typography: Tajawal / Cairo (Arabic), Inter / Poppins (Latin) — webfonts optional; stacks below
--}}
<!DOCTYPE html>
<html lang="en" dir="ltr" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
    <title>@yield('page_title', 'سيرتك | SYRTAK')</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Inter:wght@400;500;600;700&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style type="text/css">
        @media only screen and (max-width: 620px) {
            .email-shell { width: 100% !important; max-width: 100% !important; }
            .pad-main { padding-left: 24px !important; padding-right: 24px !important; }
            .otp-code { font-size: 30px !important; letter-spacing: 0.28em !important; }
            .stack { display: block !important; width: 100% !important; }
        }
    </style>
    @stack('head_styles')
</head>
<body style="margin:0;padding:0;min-width:100%;background-color:#edebe0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
    @yield('preheader')
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#edebe0;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="email-shell" style="max-width:600px;margin:0 auto;">
                    @yield('content')
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
