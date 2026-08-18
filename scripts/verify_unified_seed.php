<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Fine;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\Support\CitizenFinePaymentDemoKit;

$emails = [
    CitizenFinePaymentDemoKit::HAPPY_EMAIL,
    CitizenFinePaymentDemoKit::MIXED_EMAIL,
    CitizenFinePaymentDemoKit::BLOCKED_EMAIL,
    CitizenFinePaymentDemoKit::OTHER_EMAIL,
];

foreach ($emails as $email) {
    echo (User::query()->where('email', $email)->exists() ? 'OK' : 'MISSING')." user {$email}".PHP_EOL;
}

echo 'FLOW apps='.LicenseApplication::query()->where('application_number', 'like', 'FLOW-%')->count().PHP_EOL;
echo 'CFP fines='.Fine::query()->where('reason', 'like', '%[CFP-%')->count().PHP_EOL;
echo 'CFP payments='.Payment::query()->where('payment_number', 'like', 'PAY-CFP-%')->count().PHP_EOL;
