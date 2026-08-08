<?php

namespace App\Modules\AIAgent\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentSession;

class AgentSelectionTokenService
{
    public const PURPOSE_APPLICATION = 'select_application';

    public const PURPOSE_REQUIRED_DOCUMENT = 'select_required_document';

    public const PURPOSE_PENDING_APPLICATION = 'pending_application_selection';

    public const PURPOSE_APPOINTMENT_SLOT = 'select_appointment_slot';

    public const PURPOSE_APPOINTMENT = 'select_appointment';

    public function issue(
        User $user,
        AIAgentSession $session,
        string $purpose,
        int $applicationId,
        ?int $requiredDocumentId = null,
        int $ttlSeconds = 1800,
        ?string $workflowId = null,
        ?string $intent = null,
        ?int $slotId = null,
        ?int $appointmentId = null,
    ): string {
        $payload = [
            'uid' => $user->id,
            'sid' => $session->id,
            'aid' => $applicationId,
            'rid' => $requiredDocumentId,
            'purpose' => $purpose,
            'exp' => now()->addSeconds($ttlSeconds)->getTimestamp(),
            'n' => bin2hex(random_bytes(8)),
            'wid' => $workflowId,
            'intent' => $intent,
            'slot_id' => $slotId,
            'apid' => $appointmentId,
        ];

        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        return $encoded.'.'.$this->sign($encoded);
    }

    /**
     * @return array{
     *   uid: int,
     *   sid: int,
     *   aid: int,
     *   rid: int|null,
     *   purpose: string,
     *   exp: int,
     *   wid: string|null,
     *   intent: string|null,
     *   slot_id: int|null,
     *   appointment_id: int|null
     * }
     */
    public function verify(
        string $token,
        User $user,
        AIAgentSession $session,
        string $expectedPurpose,
        ?string $expectedWorkflowId = null,
        ?string $expectedIntent = null,
    ): array {
        $structuredCodes = in_array($expectedPurpose, [
            self::PURPOSE_PENDING_APPLICATION,
            self::PURPOSE_APPOINTMENT_SLOT,
            self::PURPOSE_APPOINTMENT,
        ], true) || $expectedWorkflowId !== null;

        $invalidCode = $structuredCodes ? 'APPLICATION_SELECTION_TOKEN_INVALID' : 'INVALID_SELECTION_TOKEN';
        $expiredCode = $structuredCodes ? 'APPLICATION_SELECTION_TOKEN_EXPIRED' : 'SELECTION_TOKEN_EXPIRED';
        $mismatchCode = $structuredCodes ? 'APPLICATION_SELECTION_TOKEN_MISMATCH' : 'INVALID_SELECTION_TOKEN';

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ApiException('رمز الاختيار غير صالح.', 422, [], [], $invalidCode);
        }

        [$encoded, $signature] = $parts;

        if (! hash_equals($this->sign($encoded), $signature)) {
            throw new ApiException('رمز الاختيار غير صالح.', 422, [], [], $invalidCode);
        }

        try {
            $payload = json_decode($this->base64UrlDecode($encoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiException('رمز الاختيار غير صالح.', 422, [], [], $invalidCode);
        }

        if (! is_array($payload)) {
            throw new ApiException('رمز الاختيار غير صالح.', 422, [], [], $invalidCode);
        }

        if ((int) ($payload['uid'] ?? 0) !== (int) $user->id) {
            throw new ApiException('رمز الاختيار غير صالح.', 422, [], [], $invalidCode);
        }

        if ((int) ($payload['sid'] ?? 0) !== (int) $session->id) {
            throw new ApiException('رمز الاختيار غير صالح لهذه الجلسة.', 422, [], [], $mismatchCode);
        }

        if ((string) ($payload['purpose'] ?? '') !== $expectedPurpose) {
            throw new ApiException('رمز الاختيار غير صالح لهذا الإجراء.', 422, [], [], $mismatchCode);
        }

        if ((int) ($payload['exp'] ?? 0) < now()->getTimestamp()) {
            throw new ApiException(
                'انتهت صلاحية رمز الاختيار. يرجى إعادة طلب الخدمة.',
                422,
                [],
                [],
                $expiredCode
            );
        }

        if ($expectedWorkflowId !== null && (string) ($payload['wid'] ?? '') !== $expectedWorkflowId) {
            throw new ApiException(
                'رمز الاختيار لا يطابق العملية الحالية.',
                422,
                [],
                [],
                $mismatchCode
            );
        }

        if ($expectedIntent !== null && (string) ($payload['intent'] ?? '') !== $expectedIntent) {
            throw new ApiException(
                'رمز الاختيار لا يطابق العملية الحالية.',
                422,
                [],
                [],
                $mismatchCode
            );
        }

        $applicationId = (int) ($payload['aid'] ?? 0);
        if ($applicationId <= 0 && ! in_array($expectedPurpose, [
            self::PURPOSE_APPOINTMENT,
            self::PURPOSE_APPOINTMENT_SLOT,
        ], true)) {
            throw new ApiException('رمز الاختيار غير صالح.', 422, [], [], $invalidCode);
        }

        return [
            'uid' => (int) $payload['uid'],
            'sid' => (int) $payload['sid'],
            'aid' => $applicationId,
            'rid' => isset($payload['rid']) && $payload['rid'] !== null ? (int) $payload['rid'] : null,
            'purpose' => (string) $payload['purpose'],
            'exp' => (int) $payload['exp'],
            'wid' => isset($payload['wid']) && is_string($payload['wid']) ? $payload['wid'] : null,
            'intent' => isset($payload['intent']) && is_string($payload['intent']) ? $payload['intent'] : null,
            'slot_id' => isset($payload['slot_id']) && $payload['slot_id'] !== null ? (int) $payload['slot_id'] : null,
            'appointment_id' => isset($payload['apid']) && $payload['apid'] !== null ? (int) $payload['apid'] : null,
        ];
    }

    private function sign(string $encoded): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $encoded, (string) config('app.key'), true)
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64url payload.');
        }

        return $decoded;
    }
}
