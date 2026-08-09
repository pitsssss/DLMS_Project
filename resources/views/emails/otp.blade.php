@extends('emails.layouts.syrtak-base')

@section('page_title', 'رمز التحقق | SYRTAK')

@section('preheader')
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
    Your SYRTAK verification code is {{ $otpCode }}. Expires in {{ $expiresMinutes }} minutes. رمز التحقق: {{ $otpCode }}
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>
@endsection

@push('head_styles')
<style type="text/css">
    @media only screen and (max-width: 620px) {
        .pad-main { padding-left: 20px !important; padding-right: 20px !important; }
        .pad-header { padding-left: 20px !important; padding-right: 20px !important; padding-top: 24px !important; padding-bottom: 24px !important; }
        .otp-code { font-size: 28px !important; letter-spacing: 0.22em !important; }
        .otp-action { padding: 18px 16px !important; }
        .brand-ar { font-size: 22px !important; }
        .brand-en { font-size: 18px !important; }
        .title-en { font-size: 17px !important; }
        .title-ar { font-size: 16px !important; }
    }
</style>
@endpush

@section('content')
{{-- Brand gold accent strip --}}
<tr>
    <td style="height:6px;line-height:6px;font-size:6px;background-color:#B9A779;">&nbsp;</td>
</tr>
{{-- Header --}}
<tr>
    <td class="pad-header" style="background-color:#002623;background:linear-gradient(160deg,#054239 0%,#002623 72%);background-color:#002623;padding:32px 36px 28px 36px;border-bottom:4px solid #988561;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td align="center" style="padding-bottom:6px;">
                    <span class="brand-ar" style="font-family:'Tajawal','Cairo','Segoe UI',Tahoma,sans-serif;font-size:26px;font-weight:700;color:#ffffff;letter-spacing:0.02em;line-height:1.25;">سيرتك</span>
                    <span class="brand-en" style="font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:20px;font-weight:600;color:#EDEBE0;letter-spacing:0.1em;">&nbsp;|&nbsp;SYRTAK</span>
                </td>
            </tr>
            <tr>
                <td align="center" style="padding-bottom:14px;">
                    <p style="margin:0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:13px;font-weight:500;color:#B9A779;line-height:1.5;">خدماتك المرورية... رقمياً وبثقة</p>
                </td>
            </tr>
            <tr>
                <td align="center" style="padding-top:14px;border-top:1px solid #428177;">
                    <p style="margin:0;font-family:'Inter',-apple-system,sans-serif;font-size:11px;font-weight:500;color:#EDEBE0;letter-spacing:0.06em;text-transform:uppercase;">Digital License Management System</p>
                    <p style="margin:6px 0 0 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:12px;font-weight:500;color:#ffffff;line-height:1.45;">نظام إدارة رخص القيادة الرقمية</p>
                </td>
            </tr>
        </table>
    </td>
</tr>
{{-- Card body --}}
<tr>
    <td style="background-color:#ffffff;border:1px solid #B9A779;border-top:none;border-radius:0 0 14px 14px;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td class="pad-main" style="padding:36px 36px 28px 36px;">
                    {{-- Titles bilingual --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        <tr>
                            <td style="padding-bottom:20px;border-bottom:1px solid #EDEBE0;">
                                <p class="title-ar" style="margin:0 0 8px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:20px;font-weight:700;color:#054239;line-height:1.35;direction:rtl;text-align:right;">تأكيد البريد الإلكتروني</p>
                                <h1 class="title-en" style="margin:0;font-family:'Inter',-apple-system,sans-serif;font-size:18px;font-weight:600;color:#002623;line-height:1.35;letter-spacing:-0.01em;">Verify Your Email Address</h1>
                            </td>
                        </tr>
                    </table>

                    {{-- Greeting --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top:24px;">
                        <tr>
                            <td>
                                @if(!empty($userName))
                                <p style="margin:0 0 6px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:16px;font-weight:600;color:#002623;line-height:1.6;direction:rtl;text-align:right;">مرحباً {{ $userName }}،</p>
                                <p style="margin:0 0 20px 0;font-family:'Inter',-apple-system,sans-serif;font-size:15px;font-weight:500;color:#3D3A3B;line-height:1.6;">Welcome, {{ $userName }}</p>
                                @else
                                <p style="margin:0 0 6px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:16px;font-weight:600;color:#002623;line-height:1.6;direction:rtl;text-align:right;">مرحباً بك في سيرتك</p>
                                <p style="margin:0 0 20px 0;font-family:'Inter',-apple-system,sans-serif;font-size:15px;font-weight:500;color:#3D3A3B;line-height:1.6;">Welcome to SYRTAK</p>
                                @endif

                                <p style="margin:0 0 8px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:14px;line-height:1.75;color:#3D3A3B;direction:rtl;text-align:right;">
                                    استخدم رمز التحقق التالي للمتابعة بأمان داخل منصة <strong style="color:#002623;">سيرتك</strong>.
                                </p>
                                <p style="margin:0 0 28px 0;font-family:'Inter',-apple-system,sans-serif;font-size:14px;line-height:1.65;color:#3D3A3B;">
                                    Use the verification code below to continue securely using <strong style="color:#002623;">SYRTAK</strong> services.
                                </p>
                            </td>
                        </tr>
                    </table>

                    {{-- OTP action block --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        <tr>
                            <td align="center" style="padding:4px 0 8px 0;">
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:420px;margin:0 auto;">
                                    <tr>
                                        <td class="otp-action" align="center" style="padding:22px 20px;background-color:#054239;border:2px solid #B9A779;border-radius:12px;">
                                            <p style="margin:0 0 4px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:12px;font-weight:700;color:#EDEBE0;letter-spacing:0.08em;">رمز التحقق</p>
                                            <p style="margin:0 0 14px 0;font-family:'Inter',-apple-system,sans-serif;font-size:10px;font-weight:600;color:#B9A779;text-transform:uppercase;letter-spacing:0.12em;">Verification code</p>
                                            <p class="otp-code" style="margin:0;font-family:'SF Mono',ui-monospace,'Cascadia Mono',Menlo,Consolas,monospace;font-size:36px;font-weight:700;color:#ffffff;letter-spacing:0.36em;line-height:1.3;text-align:center;word-break:break-all;">{{ $otpCode }}</p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>

                    {{-- Expiry bilingual --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top:24px;">
                        <tr>
                            <td style="padding:16px 18px;background-color:#F8F8F6;border:1px solid #EDEBE0;border-radius:10px;">
                                <p style="margin:0 0 6px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:13px;line-height:1.65;color:#3D3A3B;direction:rtl;text-align:right;">
                                    تنتهي صلاحية هذا الرمز خلال <strong style="color:#002623;">{{ $expiresMinutes }} دقائق</strong>.
                                </p>
                                <p style="margin:0;font-family:'Inter',-apple-system,sans-serif;font-size:13px;line-height:1.6;color:#3D3A3B;">
                                    This code expires in <strong style="color:#002623;">{{ $expiresMinutes }} minutes</strong>.
                                </p>
                            </td>
                        </tr>
                    </table>

                    {{-- Security notice — umber accent --}}
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top:20px;">
                        <tr>
                            <td style="height:3px;line-height:3px;font-size:3px;background-color:#6B1F2A;border-radius:3px 3px 0 0;">&nbsp;</td>
                        </tr>
                        <tr>
                            <td style="padding:18px 18px 18px 18px;background-color:#ffffff;border:1px solid #EDEBE0;border-top:none;border-radius:0 0 10px 10px;">
                                <p style="margin:0 0 10px 0;font-family:'Inter',-apple-system,sans-serif;font-size:11px;font-weight:700;color:#4a151e;text-transform:uppercase;letter-spacing:0.06em;">Security</p>
                                <p style="margin:0 0 8px 0;font-family:'Inter',-apple-system,sans-serif;font-size:13px;line-height:1.6;color:#161616;">
                                    Never share this code. SYRTAK staff will never ask for your verification code by phone, email, or message.
                                </p>
                                <p style="margin:0 0 10px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:13px;line-height:1.7;color:#161616;direction:rtl;text-align:right;">
                                    لا تشارك هذا الرمز مع أي شخص. لن يطلب منك موظفو سيرتك رمز التحقق عبر الهاتف أو البريد أو الرسائل.
                                </p>
                                <p style="margin:0;font-family:'Inter',-apple-system,sans-serif;font-size:12px;line-height:1.55;color:#3D3A3B;">
                                    If you did not request this email, you may ignore it safely.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            {{-- Footer --}}
            <tr>
                <td class="pad-main" style="padding:24px 36px 32px 36px;border-top:1px solid #EDEBE0;background-color:#F8F8F6;border-radius:0 0 14px 14px;">
                    <p style="margin:0 0 8px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:14px;font-weight:700;color:#002623;text-align:center;">سيرتك | SYRTAK</p>
                    <p style="margin:0 0 4px 0;font-family:'Inter',-apple-system,sans-serif;font-size:11px;line-height:1.55;color:#988561;text-align:center;">Government Digital Services Platform</p>
                    <p style="margin:0 0 14px 0;font-family:'Tajawal','Cairo',Tahoma,sans-serif;font-size:11px;line-height:1.5;color:#3D3A3B;text-align:center;">منصة الخدمات الحكومية الرقمية</p>
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
