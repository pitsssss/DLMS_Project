<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentSafetyRules;

class AgentIntentDetector
{
    /**
     * Rule-based fallback when Gemini is unavailable or returns invalid JSON.
     *
     * @return array{
     *   intent: string,
     *   confidence: float,
     *   language: string,
     *   reply: string,
     *   missing_slots: array<int, string>,
     *   proposed_action: array<string, mixed>|null,
     *   requires_confirmation: bool,
     *   safety_status: string,
     *   requires_human_support: bool
     * }
     */
    public function detectFallback(string $message, AIAgentSession $session, ?string $languageHint = null): array
    {
        $language = $languageHint ?? 'ar';
        $normalized = mb_strtolower($message);
        $context = $session->context ?? [];

        if (AgentSafetyRules::messageLooksAdminRelated($message)) {
            return $this->adminDeniedResponse($language);
        }

        if ($this->isOutOfScope($normalized)) {
            return $this->outOfScopeResponse($language);
        }

        if ($this->matchesNewLicenseIntent($normalized)) {
            $licenseType = $context['license_type_code'] ?? $this->extractLicenseTypeFromMessage($normalized);

            if ($licenseType === null) {
                return [
                    'intent' => AgentIntent::CreateNewLicenseApplication->value,
                    'confidence' => 0.72,
                    'language' => $language,
                    'reply' => $language === 'ar'
                        ? 'يمكنني مساعدتك في إنشاء طلب رخصة جديدة. ما نوع الرخصة التي تريدها؟ خاصة، عامة، شاحنة، أم حافلة؟'
                        : 'I can help you prepare a new license application. Which license type do you need: private, public, truck, or bus?',
                    'missing_slots' => ['license_type'],
                    'proposed_action' => null,
                    'requires_confirmation' => false,
                    'safety_status' => 'safe',
                    'requires_human_support' => false,
                ];
            }

            return [
                'intent' => AgentIntent::CreateNewLicenseApplication->value,
                'confidence' => 0.78,
                'language' => $language,
                'reply' => $language === 'ar'
                    ? 'سيتم تجهيز طلب إصدار رخصة قيادة '.$this->licenseLabelAr($licenseType).'. هل تؤكد المتابعة؟'
                    : 'I will prepare a new '.$licenseType.' license application. Do you want to continue?',
                'missing_slots' => [],
                'proposed_action' => [
                    'name' => 'create_application',
                    'arguments' => [
                        'license_type_code' => $licenseType,
                        'service_type_code' => 'new_license',
                    ],
                ],
                'requires_confirmation' => true,
                'safety_status' => 'safe',
                'requires_human_support' => false,
            ];
        }

        return [
            'intent' => AgentIntent::GeneralHelp->value,
            'confidence' => 0.45,
            'language' => $language,
            'reply' => $language === 'ar'
                ? 'أنا مساعد خدمات رخص القيادة. يمكنني مساعدتك في طلب رخصة جديدة، متابعة الطلب، المستندات، الدفع، المواعيد، النتائج، الرخص، والمخالفات. كيف يمكنني مساعدتك؟'
                : 'I assist with driving license services only. I can help with new applications, status, documents, payments, appointments, results, licenses, and fines. How can I help?',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ];
    }

    private function matchesNewLicenseIntent(string $normalized): bool
    {
        $patterns = [
            'رخصة جديدة',
            'رخصه جديده',
            'بدي رخصة',
            'بدي رخصه',
            'new license',
            'new driving license',
            'apply for license',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function extractLicenseTypeFromMessage(string $normalized): ?string
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
            'رخصة خاصة' => 'private',
            'رخصه خاصه' => 'private',
        ];

        foreach ($map as $needle => $code) {
            if (str_contains($normalized, $needle)) {
                return $code;
            }
        }

        return null;
    }

    private function isOutOfScope(string $normalized): bool
    {
        $patterns = [
            'weather',
            'football',
            'bitcoin',
            'recipe',
            'الطقس',
            'كرة القدم',
            'طبخ',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function adminDeniedResponse(string $language): array
    {
        return [
            'intent' => AgentIntent::AdminActionDenied->value,
            'confidence' => 0.95,
            'language' => $language,
            'reply' => $language === 'ar'
                ? 'هذا الإجراء يتطلب موظفاً مخولاً. لا يمكنني تنفيذه نيابة عنك.'
                : 'This action requires an authorized employee. I cannot perform it for you.',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'blocked',
            'requires_human_support' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outOfScopeResponse(string $language): array
    {
        return [
            'intent' => AgentIntent::OutOfScope->value,
            'confidence' => 0.9,
            'language' => $language,
            'reply' => $language === 'ar'
                ? 'أنا مساعد خدمات رخص القيادة فقط. يرجى طرح سؤال متعلق بالرخصة أو الطلب أو المواعيد أو المستندات.'
                : 'I only support driving license services. Please ask about licenses, applications, appointments, or documents.',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ];
    }

    private function licenseLabelAr(string $code): string
    {
        return match ($code) {
            'private' => 'خاصة',
            'public' => 'عامة',
            'truck' => 'شاحنة',
            'bus' => 'حافلة',
            default => $code,
        };
    }
}
