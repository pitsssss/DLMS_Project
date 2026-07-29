<?php

namespace App\Modules\Appointments\Support;

use App\Models\AppointmentSlot;

final class SlotIdentity
{
    public static function buildKey(
        int $testTypeId,
        ?int $appointmentCenterId,
        string $date,
        string $startTime,
        string $endTime,
    ): string {
        return sprintf(
            'tt:%d|ac:%d|d:%s|st:%s|et:%s',
            $testTypeId,
            $appointmentCenterId ?? 0,
            $date,
            self::normalizeTime($startTime),
            self::normalizeTime($endTime),
        );
    }

    public static function keyForSlot(AppointmentSlot $slot): string
    {
        return self::buildKey(
            (int) $slot->test_type_id,
            $slot->appointment_center_id !== null ? (int) $slot->appointment_center_id : null,
            $slot->date->format('Y-m-d'),
            (string) $slot->start_time,
            (string) $slot->end_time,
        );
    }

    public static function normalizeTime(string $time): string
    {
        $time = trim($time);

        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time.':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) === 1) {
            return $time;
        }

        return $time;
    }

    public static function normalizedCenterId(?int $appointmentCenterId): ?int
    {
        return $appointmentCenterId !== null && $appointmentCenterId > 0 ? $appointmentCenterId : null;
    }
}
