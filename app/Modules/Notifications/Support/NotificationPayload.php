<?php

namespace App\Modules\Notifications\Support;

use App\Enums\NotificationType;
use InvalidArgumentException;

/**
 * Builds lean, machine-readable notification `data` payloads.
 */
final class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(NotificationType $type, array $data): array
    {
        $allowed = array_fill_keys($type->allowedDataKeys(), true);
        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! isset($allowed[$key])) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value)) {
                $normalized[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                $normalized[$key] = $value;

                continue;
            }

            // Reject nested structures / unexpected types for safety.
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function assertAndNormalize(NotificationType $type, array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || ! in_array($key, $type->allowedDataKeys(), true)) {
                throw new InvalidArgumentException(
                    "Notification data key [{$key}] is not allowed for type [{$type->value}]."
                );
            }
        }

        return self::normalize($type, $data);
    }
}
