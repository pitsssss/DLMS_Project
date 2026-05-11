@extends('emails.layouts.syrtak-base')

@section('page_title', 'رمز التحقق | SYRTAK')

@section('preheader')
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
    Your SYRTAK verification code is {{ $otpCode }}. Expires in {{ $expiresMinutes }} minutes. رمز التحقق: {{ $otpCode }}
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>
@endsection

@section('content')
{{-- 8px gold accent strip --}}
<tr>
    <td style="height:8px;line-height:8px;font-size:8px;background-color:#b9a779;">&nbsp;</td>
</tr>
{{-- Header: forest green --}}
<tr>
    <td style="background-color:#002623;background:linear-gradient(180deg,#054239 0%,#002623 100%);background-color:#002623;padding:28px 32px;border-bottom:3px solid #988561;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td align="center" style="padding-bottom:8px;">
                    <span style="font-family:'Tajawal','Cairo','Segoe UI',Tahoma,sans-serif;font-size:26px;font-weight:700;color:#ffffff;letter-spacing:0.02em;line-height:1.2;">سيرتك</span>
                    <span style="font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:22px;font-weight:600;color:#edebe0;letter-spacing:0.12em;">&nbsp;|&nbsp;SYRTAK</span>
                </td>
            </tr>
            <tr>
                <td align="center" style="padding-bottom:4px;">
                    <p style="margin:0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:13px;font-weight:500;color:#b9a779;line-height:1.5;">خدماتك المرورية... رقمياً وبثقة</p>
                </td>
            </tr>
            <tr>
                <td align="center" style="padding-top:12px;border-top:1px solid #428177;">
                    <p style="margin:0;font-family:'Inter',-apple-system,sans-serif;font-size:11px;font-weight:500;color:#edebe0;letter-spacing:0.06em;text-transform:uppercase;">Digital License Management System</p>
                    <p style="margin:6px 0 0 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:12px;font-weight:500;color:#ffffff;line-height:1.45;">نظام إدارة رخص القيادة الرقمية</p>
                </td>
            </tr>
        </table>
    </td>
</tr>
{{-- Card body --}}
<tr>
    <td style="background-color:#ffffff;border:1px solid #b9a779;border-top:none;border-radius:0 0 12px 12px;box-shadow:0 8px 32px rgba(0,38,35,0.08);">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td class="pad-main" style="padding:40px 40px 32px 40px;">
                    {{-- Titles bilingual --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        <tr>
                            <td valign="top" width="50%" class="stack" style="padding-bottom:16px;padding-left:8px;">
                                <h1 style="margin:0 0 8px 0;font-family:'Inter',-apple-system,sans-serif;font-size:20px;font-weight:600;color:#002623;line-height:1.35;letter-spacing:-0.02em;">Verify Your Email Address</h1>
                                <p style="margin:0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:18px;font-weight:700;color:#054239;line-height:1.4;">تأكيد البريد الإلكتروني</p>
                            </td>
                        </tr>
                    </table>

                    {{-- Greeting --}}
                    @if(!empty($userName))
                    <p style="margin:0 0 8px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:16px;font-weight:600;color:#002623;line-height:1.6;">مرحباً {{ $userName }}،</p>
                    <p style="margin:0 0 24px 0;font-family:'Inter',-apple-system,sans-serif;font-size:15px;font-weight:500;color:#3d3a3b;line-height:1.6;">Welcome, {{ $userName }}</p>
                    @else
                    <p style="margin:0 0 8px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:16px;font-weight:600;color:#002623;line-height:1.6;">مرحباً بك في سيرتك</p>
                    <p style="margin:0 0 24px 0;font-family:'Inter',-apple-system,sans-serif;font-size:15px;font-weight:500;color:#3d3a3b;line-height:1.6;">Welcome to SYRTAK</p>
                    @endif

                    <p style="margin:0 0 8px 0;font-family:'Inter',-apple-system,sans-serif;font-size:14px;line-height:1.65;color:#3d3a3b;">
                        Use the verification code below to continue securely using <strong style="color:#002623;">SYRTAK</strong> services.
                    </p>
                    <p style="margin:0 0 32px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:14px;line-height:1.7;color:#3d3a3b;direction:rtl;text-align:right;">
                        استخدم رمز التحقق التالي للمتابعة بأمان داخل منصة <strong style="color:#002623;">سيرتك</strong>.
                    </p>

                    {{-- OTP box --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        <tr>
                            <td align="center" style="padding:32px 24px;background-color:#edebe0;border:2px solid #b9a779;border-top:3px solid #428177;border-radius:12px;box-shadow:inset 0 1px 0 rgba(255,255,255,0.6),0 2px 8px rgba(0,38,35,0.06);">
                                <p style="margin:0 0 8px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:11px;font-weight:700;color:#054239;text-transform:uppercase;letter-spacing:0.12em;">رمز التحقق</p>
                                <p style="margin:0 0 12px 0;font-family:'Inter',-apple-system,sans-serif;font-size:10px;font-weight:600;color:#428177;text-transform:uppercase;letter-spacing:0.14em;">Verification code</p>
                                <p class="otp-code" style="margin:0;font-family:'SF Mono',ui-monospace,'Cascadia Mono',Menlo,Consolas,monospace;font-size:38px;font-weight:700;color:#002623;letter-spacing:0.42em;line-height:1.25;text-align:center;word-break:break-all;">{{ $otpCode }}</p>
                            </td>
                        </tr>
                    </table>

                    {{-- Expiry bilingual --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top:24px;">
                        <tr>
                            <td style="padding:16px 0;border-top:1px solid #edebe0;">
                                <p style="margin:0 0 6px 0;font-family:'Inter',-apple-system,sans-serif;font-size:13px;line-height:1.6;color:#3d3a3b;">
                                    This code expires in <strong style="color:#002623;">{{ $expiresMinutes }} minutes</strong>.
                                </p>
                                <p style="margin:0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:13px;line-height:1.65;color:#3d3a3b;direction:rtl;text-align:right;">
                                    تنتهي صلاحية هذا الرمز خلال <strong style="color:#002623;">{{ $expiresMinutes }} دقائق</strong>.
                                </p>
                            </td>
                        </tr>
                    </table>

                    {{-- Security notice — umber accent, restrained --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top:8px;">
                        <tr>
                            <td style="padding:20px 20px 20px 16px;background-color:#ffffff;border:1px solid #edebe0;border-left:4px solid #6b1f2a;border-radius:8px;">
                                <p style="margin:0 0 10px 0;font-family:'Inter',-apple-system,sans-serif;font-size:12px;font-weight:700;color:#4a151e;text-transform:uppercase;letter-spacing:0.06em;">Security</p>
                                <p style="margin:0 0 8px 0;font-family:'Inter',-apple-system,sans-serif;font-size:13px;line-height:1.6;color:#161616;">
                                    Never share this code. SYRTAK staff will never ask for your verification code by phone, email, or message.
                                </p>
                                <p style="margin:0 0 8px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:13px;line-height:1.65;color:#161616;direction:rtl;text-align:right;">
                                    لا تشارك هذا الرمز مع أي شخص. لن يطلب منك موظفو سيرتك رمز التحقق عبر الهاتف أو البريد أو الرسائل.
                                </p>
                                <p style="margin:0;font-family:'Inter',-apple-system,sans-serif;font-size:12px;line-height:1.55;color:#3d3a3b;">
                                    If you did not request this email, you may ignore it safely.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            {{-- Footer --}}
            <tr>
                <td class="pad-main" style="padding:0 40px 32px 40px;border-top:1px solid #edebe0;">
                    <p style="margin:0 0 12px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:14px;font-weight:600;color:#002623;text-align:center;">سيرتك | SYRTAK</p>
                    <p style="margin:0 0 8px 0;font-family:'Inter',-apple-system,sans-serif;font-size:11px;line-height:1.55;color:#988561;text-align:center;">Government Digital Services Platform</p>
                    <p style="margin:0 0 16px 0;font-family:'Inter',-apple-system,sans-serif;font-size:11px;line-height:1.5;color:#3d3a3b;text-align:center;">منصة الخدمات الحكومية الرقمية</p>
                    <p style="margin:0;font-family:'Inter',-apple-system,sans-serif;font-size:10px;line-height:1.5;color:#988561;text-align:center;">
                        &copy; {{ date('Y') }} SYRTAK — Digital License Management System. All rights reserved.
                    </p>
                </td>
            </tr>
        </table>
    </td>
</tr>
<tr>
    <td style="padding:24px 16px 0 16px;">
        <p style="margin:0;font-family:'Inter',-apple-system,sans-serif;font-size:10px;line-height:1.5;color:#988561;text-align:center;">
            This is an automated message. Please do not reply to this email.<br>
            <span style="font-family:'Tajawal','Cairo',Tahoma,sans-serif;">رسالة آلية — يرجى عدم الرد على هذا البريد.</span>
        </p>
    </td>
</tr>
@endsection
