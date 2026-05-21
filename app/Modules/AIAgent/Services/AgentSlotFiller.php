<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Models\AIAgentSession;

class AgentSlotFiller
{
    private const LICENSE_TYPE_CODES = ['private', 'public', 'truck', 'bus'];

    /**
     * Merge slots from session context and the latest user message into the model payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(AIAgentSession $session, array $payload, string $userMessage): array
    {
        $context = $session->context ?? [];
        $normalized = mb_strtolower(trim($userMessage));

        $licenseType = $context['license_type_code'] ?? null;
        if ($licenseType === null) {
            $licenseType = $this->extractLicenseType($normalized);
        }

        if ($licenseType !== null) {
            $context['license_type_code'] = $licenseType;
        }

        if (($payload['intent'] ?? null) === 'create_new_license_application') {
            $missing = $payload['missing_slots'] ?? [];

            if ($licenseType === null) {
                if (! in_array('license_type', $missing, true)) {
                    $missing[] = 'license_type';
                }
                $payload['missing_slots'] = array_values(array_unique($missing));
                $payload['proposed_action'] = null;
                $payload['requires_confirmation'] = false;
            } else {
                $payload['missing_slots'] = array_values(array_filter(
                    $missing,
                    static fn (string $slot) => $slot !== 'license_type'
                ));

                if (empty($payload['missing_slots']) && empty($payload['proposed_action'])) {
                    $payload['proposed_action'] = [
                        'name' => 'create_application',
                        'arguments' => [
                            'license_type_code' => $licenseType,
                            'service_type_code' => $context['service_type_code'] ?? 'new_license',
                        ],
                    ];
                    $payload['requires_confirmation'] = true;
                }
            }
        }

        $session->context = $context;

        return $payload;
    }

    private function extractLicenseType(string $normalized): ?string
    {
        $map = [
            'خاصة' => 'private',
            'خاصه' => 'private',
            'private' => 'private',
            'عامة' => 'public',
            'عامه' => 'public',
            'public' => 'public',
            'شاحنة' => 'truck',
            'truck' => 'truck',
            'حافلة' => 'bus',
            'bus' => 'bus',
        ];

        foreach ($map as $needle => $code) {
            if (str_contains($normalized, $needle)) {
                return $code;
            }
        }

        foreach (self::LICENSE_TYPE_CODES as $code) {
            if (preg_match('/\b'.preg_quote($code, '/').'\b/u', $normalized)) {
                return $code;
            }
        }

        return null;
    }
}
