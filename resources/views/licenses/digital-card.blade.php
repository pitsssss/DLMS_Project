<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: dejavusans, sans-serif;
            direction: rtl;
            color: #1a1a1a;
            font-size: 12pt;
        }
        .card {
            border: 2px solid #0b3d5c;
            border-radius: 8px;
            padding: 24px;
            margin-top: 40px;
        }
        .header {
            text-align: center;
            margin-bottom: 18px;
        }
        .authority {
            font-size: 14pt;
            font-weight: bold;
            color: #0b3d5c;
        }
        .title {
            font-size: 16pt;
            font-weight: bold;
            margin-top: 6px;
        }
        .row {
            margin: 8px 0;
        }
        .label {
            color: #555;
            font-size: 10pt;
        }
        .value {
            font-weight: bold;
            font-size: 12pt;
        }
        .status {
            display: inline-block;
            padding: 4px 10px;
            border: 1px solid #0b3d5c;
            border-radius: 4px;
            margin-top: 4px;
        }
        .qr {
            text-align: center;
            margin-top: 20px;
        }
        .guidance {
            margin-top: 10px;
            font-size: 9pt;
            color: #444;
            text-align: center;
        }
        .footer {
            margin-top: 24px;
            font-size: 8pt;
            color: #777;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="header">
        <div class="authority">{{ $payload['authority'] }}</div>
        <div class="title">{{ $payload['title'] }}</div>
    </div>

    <div class="row">
        <div class="label">رقم الرخصة</div>
        <div class="value">{{ $payload['license_number'] }}</div>
    </div>
    <div class="row">
        <div class="label">اسم حامل الرخصة</div>
        <div class="value">{{ $payload['holder_name'] }}</div>
    </div>
    <div class="row">
        <div class="label">فئة الرخصة</div>
        <div class="value">{{ $payload['license_type']['label'] ?? '—' }}</div>
    </div>
    <div class="row">
        <div class="label">تاريخ الإصدار</div>
        <div class="value">{{ $payload['issue_date'] }}</div>
    </div>
    <div class="row">
        <div class="label">تاريخ الانتهاء</div>
        <div class="value">{{ $payload['expiry_date'] }}</div>
    </div>
    <div class="row">
        <div class="label">الحالة</div>
        <div class="status">{{ $payload['status_label'] }}</div>
    </div>

    @if(!empty($payload['verification_url']))
        <div class="qr">
            <img src="{{ $qr }}" width="140" height="140" alt="QR">
            <div class="guidance">{{ $payload['verification_guidance'] }}</div>
        </div>
    @endif

    <div class="footer">{{ $generated_at }}</div>
</div>
</body>
</html>
