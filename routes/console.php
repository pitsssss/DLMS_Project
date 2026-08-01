<?php

use App\Console\Commands\ReconcilePendingPaymentsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(ReconcilePendingPaymentsCommand::class)
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command(\App\Console\Commands\SyncExpiredLicensesCommand::class)
    ->dailyAt('00:15')
    ->timezone(config('dlms.business_timezone', 'Asia/Damascus'))
    ->withoutOverlapping();

Schedule::command(\App\Console\Commands\ReconcileEmployeeSessionsCommand::class)
    ->hourly()
    ->withoutOverlapping();

Schedule::command(\App\Console\Commands\PruneEmployeeSessionsCommand::class, ['--dry-run' => true])
    ->weeklyOn(0, '03:30')
    ->timezone(config('dlms.business_timezone', 'Asia/Damascus'))
    ->withoutOverlapping();
