<?php

namespace App\Modules\Notifications\Support;

use App\Models\TestAppointment;
use App\Support\RecipientNotificationTranslator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * Citizen-facing appointment display placeholders (localized body only).
 */
final class AppointmentNotificationCopy
{
    /**
     * @return array{test_name: string, date: string, time: string, center: string}
     */
    public static function placeholders(TestAppointment $appointment, ?string $locale = null): array
    {
        $appointment->loadMissing(['testType', 'appointmentSlot.appointmentCenter']);

        $locale = $locale !== null && $locale !== ''
            ? $locale
            : RecipientNotificationTranslator::localeForUserId((int) ($appointment->citizen_id ?? 0));

        $tz = (string) config('dlms.business_timezone', 'Asia/Damascus');
        $scheduled = $appointment->scheduled_at instanceof Carbon
            ? $appointment->scheduled_at->copy()->timezone($tz)
            : Carbon::parse((string) $appointment->scheduled_at)->timezone($tz);

        $testType = $appointment->testType;
        $testName = '';
        if ($testType !== null) {
            $catalogKey = 'messages.catalog.test_types.'.$testType->code;
            $testName = Lang::has($catalogKey, $locale)
                ? RecipientNotificationTranslator::get($catalogKey, [], $locale)
                : (string) $testType->name;
        }

        $center = (string) ($appointment->appointmentSlot?->appointmentCenter?->name ?? '');

        return [
            'test_name' => $testName !== '' ? $testName : (string) ($testType?->name ?? ''),
            'date' => $scheduled->format('Y-m-d'),
            'time' => $scheduled->format('H:i'),
            'center' => $center !== '' ? $center : '—',
        ];
    }
}
