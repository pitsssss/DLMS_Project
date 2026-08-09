<?php

namespace App\Modules\Notifications\Support;

use App\Models\TestAppointment;
use App\Support\CitizenCatalogLabel;
use Carbon\Carbon;

/**
 * Citizen-facing appointment display placeholders (localized body only).
 */
final class AppointmentNotificationCopy
{
    /**
     * @return array{test_name: string, date: string, time: string, center: string}
     */
    public static function placeholders(TestAppointment $appointment): array
    {
        $appointment->loadMissing(['testType', 'appointmentSlot.appointmentCenter']);

        $tz = (string) config('dlms.business_timezone', 'Asia/Damascus');
        $scheduled = $appointment->scheduled_at instanceof Carbon
            ? $appointment->scheduled_at->copy()->timezone($tz)
            : Carbon::parse((string) $appointment->scheduled_at)->timezone($tz);

        $testType = $appointment->testType;
        $testName = $testType !== null
            ? CitizenCatalogLabel::testType((string) $testType->code, (string) $testType->name)
            : '';

        $center = (string) ($appointment->appointmentSlot?->appointmentCenter?->name ?? '');

        return [
            'test_name' => $testName !== '' ? $testName : (string) ($testType?->name ?? ''),
            'date' => $scheduled->format('Y-m-d'),
            'time' => $scheduled->format('H:i'),
            'center' => $center !== '' ? $center : '—',
        ];
    }
}
