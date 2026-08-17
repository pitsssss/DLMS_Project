<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            padding: 0;
            font-family: dejavusans, sans-serif;
            direction: rtl;
            color: #002623;
            width: 85.60mm;
            height: 53.98mm;
        }
        .face {
            position: relative;
            width: 85.60mm;
            height: 53.98mm;
            overflow: hidden;
            background: #f7f4ef;
            border: 0.45mm solid #b9a779;
        }
        .face-inner {
            position: relative;
            width: 84.70mm;
            height: 53.08mm;
            margin: 0.45mm;
            background: #f7f4ef;
        }
        .pattern {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: repeating-linear-gradient(
                115deg,
                rgba(5,66,57,0.045) 0,
                rgba(5,66,57,0.045) 0.35mm,
                transparent 0.35mm,
                transparent 1.6mm
            );
        }
        .gold-edge {
            position: absolute;
            top: 0;
            right: 0;
            width: 1.4mm;
            height: 100%;
            background: #b9a779;
        }
        .header {
            background: #054239;
            color: #ffffff;
            height: 11.2mm;
        }
        .header td { vertical-align: middle; padding: 1.1mm 2mm; }
        .logo { width: 8.2mm; height: 8.2mm; }
        .authority {
            font-size: 6.2pt;
            font-weight: bold;
            line-height: 1.25;
            color: #ffffff;
        }
        .title {
            font-size: 5.2pt;
            color: #b9a779;
            letter-spacing: 0.1mm;
        }
        .body-table { width: 100%; }
        .body-table td { vertical-align: top; }
        .photo-cell { width: 24.5mm; padding: 2.2mm 1.6mm 2mm 2.2mm; }
        .photo {
            width: 22mm;
            height: 28mm;
            border: 0.25mm solid #b9a779;
            background: #054239;
            overflow: hidden;
        }
        .photo img { width: 22mm; height: 28mm; }
        .photo-fallback {
            width: 22mm;
            height: 28mm;
            background: #054239;
            text-align: center;
        }
        .silhouette-head {
            margin: 5.5mm auto 0 auto;
            width: 7mm;
            height: 7mm;
            background: #b9a779;
            border-radius: 3.5mm;
        }
        .silhouette-body {
            margin: 1.2mm auto 0 auto;
            width: 12mm;
            height: 10mm;
            background: #b9a779;
            border-radius: 6mm 6mm 0 0;
        }
        .fields { padding: 2mm 2.4mm 1.4mm 1.4mm; }
        .label {
            font-size: 4.4pt;
            color: #428177;
            line-height: 1.15;
        }
        .value {
            font-size: 7.4pt;
            font-weight: bold;
            color: #002623;
            line-height: 1.2;
        }
        .name { font-size: 9pt; padding-bottom: 1.1mm; }
        .number {
            font-size: 8.4pt;
            font-family: dejavusansmono, dejavusans, monospace;
            direction: ltr;
            text-align: left;
            padding-bottom: 1.4mm;
        }
        .pair td { width: 50%; padding: 0.5mm 0 0.9mm 0; vertical-align: top; }
        .status {
            display: inline-block;
            font-size: 5.4pt;
            font-weight: bold;
            color: #054239;
            border: 0.2mm solid #b9a779;
            padding: 0.3mm 1.2mm;
        }
        .footer-strip {
            position: absolute;
            left: 0;
            bottom: 0;
            width: 100%;
            height: 3.4mm;
            background: #002623;
            color: #b9a779;
            font-size: 4.3pt;
            text-align: center;
            line-height: 3.4mm;
        }
        .invalid {
            position: absolute;
            left: 8mm;
            top: 22mm;
            width: 70mm;
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            color: #7f1d1d;
            background: rgba(255,255,255,0.72);
            border: 0.3mm solid #7f1d1d;
            padding: 1.2mm 2mm;
        }
        .back-header {
            background: #002623;
            color: #ffffff;
            height: 9.6mm;
        }
        .back-header td { vertical-align: middle; padding: 1.2mm 2.2mm; }
        .qr-wrap { text-align: center; padding: 2.4mm 2mm 1mm 2mm; }
        .qr-wrap img { width: 17.5mm; height: 17.5mm; }
        .back-fields { padding: 0 2.4mm; }
        .host {
            font-size: 5pt;
            color: #054239;
            text-align: center;
            padding-top: 0.8mm;
            direction: ltr;
        }
        .instruction {
            font-size: 5pt;
            color: #428177;
            text-align: center;
            padding: 0 3mm 0.6mm 3mm;
        }
    </style>
</head>
<body>
@php
    $labels = $payload['labels'] ?? [];
    $isValid = (bool) ($payload['is_valid'] ?? false);
    $typeLabel = $payload['license_type']['label'] ?? '—';
    $typeCode = $payload['license_type']['code'] ?? '';
    $host = $payload['verification_host'] ?? null;
    $hostDisplay = $host ?: ($labels['verify_host_fallback'] ?? '');
@endphp

<div class="face">
    <div class="face-inner">
        <div class="pattern"></div>
        <div class="gold-edge"></div>
        <table class="header" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="11mm">
                    @if(!empty($logo))
                        <img src="{{ $logo }}" class="logo" alt="SYRTAK">
                    @endif
                </td>
                <td>
                    <div class="authority">{{ $payload['authority'] }}</div>
                    <div class="title">{{ $payload['title'] }}</div>
                </td>
            </tr>
        </table>
        <table class="body-table" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td class="photo-cell">
                    @if(!empty($portrait))
                        <div class="photo"><img src="{{ $portrait }}" alt=""></div>
                    @else
                        <div class="photo photo-fallback">
                            <div class="silhouette-head"></div>
                            <div class="silhouette-body"></div>
                        </div>
                    @endif
                </td>
                <td class="fields">
                    <div class="label">{{ $labels['name'] ?? '' }}</div>
                    <div class="value name">{{ $payload['holder_name'] ?: '—' }}</div>
                    <div class="label">{{ $labels['license_number'] ?? '' }}</div>
                    <div class="value number">{{ $payload['license_number'] }}</div>
                    <table class="pair" width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td>
                                <div class="label">{{ $labels['type'] ?? '' }}</div>
                                <div class="value">{{ $typeLabel }}@if($typeCode) <span style="font-size:5.2pt;color:#428177">{{ $typeCode }}</span>@endif</div>
                            </td>
                            <td>
                                <div class="label">{{ $labels['status'] ?? '' }}</div>
                                <div class="status">{{ $payload['status_label'] }}</div>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="label">{{ $labels['issue_date'] ?? '' }}</div>
                                <div class="value">{{ $payload['issue_date'] }}</div>
                            </td>
                            <td>
                                <div class="label">{{ $labels['expiry_date'] ?? '' }}</div>
                                <div class="value">{{ $payload['expiry_date'] }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        <div class="footer-strip">{{ $payload['authority'] }}</div>
        @if(! $isValid)
            <div class="invalid">{{ $labels['invalid'] ?? '' }}</div>
        @endif
    </div>
</div>

<pagebreak />

<div class="face">
    <div class="face-inner">
        <div class="pattern"></div>
        <table class="back-header" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="11mm">
                    @if(!empty($logo))
                        <img src="{{ $logo }}" class="logo" alt="SYRTAK">
                    @endif
                </td>
                <td>
                    <div class="authority">{{ $payload['authority'] }}</div>
                    <div class="title">{{ $labels['verify_heading'] ?? '' }}</div>
                </td>
            </tr>
        </table>
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="38mm" class="back-fields">
                    <div class="label">{{ $labels['license_number'] ?? '' }}</div>
                    <div class="value number">{{ $payload['license_number'] }}</div>
                    <div class="label">{{ $labels['type'] ?? '' }}</div>
                    <div class="value">{{ $typeLabel }}@if($typeCode) <span style="font-size:5pt;color:#428177">({{ $typeCode }})</span>@endif</div>
                    <div class="label">{{ $labels['issue_date'] ?? '' }}</div>
                    <div class="value">{{ $payload['issue_date'] }}</div>
                    <div class="label">{{ $labels['expiry_date'] ?? '' }}</div>
                    <div class="value">{{ $payload['expiry_date'] }}</div>
                    <div class="label">{{ $labels['status'] ?? '' }}</div>
                    <div class="value">{{ $payload['status_label'] }}</div>
                </td>
                <td class="qr-wrap">
                    @if(!empty($qr))
                        <img src="{{ $qr }}" alt="QR">
                    @endif
                    <div class="host">{{ $hostDisplay }}</div>
                </td>
            </tr>
        </table>
        <div class="instruction">{{ $labels['verify_instruction'] ?? $payload['verification_guidance'] }}</div>
        <div class="footer-strip">{{ $labels['official_use'] ?? '' }} · {{ $generated_at }}</div>
        @if(! $isValid)
            <div class="invalid">{{ $labels['invalid'] ?? '' }}</div>
        @endif
    </div>
</div>
</body>
</html>
