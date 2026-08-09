<?php

namespace App\Modules\Firebase\Support;

/**
 * Classifies FCM HTTP v1 error payloads without retaining sensitive content.
 */
final class FcmErrorClassifier
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function classify(int $httpStatus, array $body): FcmErrorCategory
    {
        $fcmErrorCode = $this->extractFcmErrorCode($body);
        $status = strtoupper((string) data_get($body, 'error.status', ''));

        if ($fcmErrorCode === 'UNREGISTERED'
            || $status === 'NOT_FOUND'
            || $httpStatus === 404) {
            return FcmErrorCategory::Unregistered;
        }

        if (in_array($fcmErrorCode, ['INVALID_ARGUMENT', 'SENDER_ID_MISMATCH'], true)
            || $status === 'INVALID_ARGUMENT'
            || $httpStatus === 400) {
            // Some invalid registration tokens surface as INVALID_ARGUMENT.
            if ($this->messageSuggestsUnregistered((string) data_get($body, 'error.message', ''))) {
                return FcmErrorCategory::Unregistered;
            }

            return FcmErrorCategory::InvalidArgument;
        }

        if (in_array($status, ['UNAUTHENTICATED', 'PERMISSION_DENIED'], true)
            || in_array($httpStatus, [401, 403], true)) {
            return FcmErrorCategory::Authentication;
        }

        if ($status === 'RESOURCE_EXHAUSTED'
            || $fcmErrorCode === 'QUOTA_EXCEEDED'
            || $httpStatus === 429) {
            return FcmErrorCategory::Quota;
        }

        if (in_array($status, ['UNAVAILABLE', 'INTERNAL', 'DEADLINE_EXCEEDED'], true)
            || $httpStatus >= 500) {
            return FcmErrorCategory::Server;
        }

        return FcmErrorCategory::Unknown;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function providerErrorCode(array $body): ?string
    {
        $code = $this->extractFcmErrorCode($body);

        return $code !== '' ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function providerStatus(array $body): ?string
    {
        $status = data_get($body, 'error.status');

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractFcmErrorCode(array $body): string
    {
        $details = data_get($body, 'error.details', []);
        if (! is_array($details)) {
            return '';
        }

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $type = (string) ($detail['@type'] ?? '');
            if (str_contains($type, 'FcmError') && isset($detail['errorCode'])) {
                return strtoupper((string) $detail['errorCode']);
            }
        }

        return '';
    }

    private function messageSuggestsUnregistered(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'not a valid fcm registration token')
            || str_contains($lower, 'registration token is not a valid')
            || str_contains($lower, 'requested entity was not found');
    }
}
