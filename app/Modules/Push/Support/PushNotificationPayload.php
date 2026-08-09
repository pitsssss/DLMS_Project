<?php

namespace App\Modules\Push\Support;

/**
 * Builds lean FCM data payloads from stored notifications.
 */
final class PushNotificationPayload
{
    private const FORBIDDEN_KEYS = [
        'event_key',
        'user_id',
        'token',
        'token_hash',
        'national_id',
        'phone',
        'email',
        'address',
        'password',
        'document_path',
        'file_path',
        'access_token',
        'authorization',
    ];

    /**
     * @param  array<string, mixed>|null  $notificationData
     * @return array<string, string>
     */
    public static function buildData(int $notificationId, ?string $type, ?array $notificationData): array
    {
        $data = [
            'notification_id' => (string) $notificationId,
        ];

        if (is_string($type) && $type !== '') {
            $data['type'] = $type;
        }

        foreach ($notificationData ?? [] as $key => $value) {
            if (! is_string($key) || in_array($key, self::FORBIDDEN_KEYS, true)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $data[$key] = $value;

                continue;
            }

            if (is_bool($value)) {
                $data[$key] = $value ? '1' : '0';

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $data[$key] = (string) $value;
            }
        }

        return $data;
    }
}
