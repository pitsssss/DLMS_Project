<?php

namespace App\Modules\AIAgent\Support;

use App\Modules\AIAgent\Enums\AgentIntent;

class AgentSafetyRules
{
    /** @var list<string> Phase 9B: safe citizen actions that may be executed after confirmation. */
    public const PHASE_9B_EXECUTABLE_ACTIONS = [
        'create_application',
        'get_application_status',
        'get_application_next_step',
        'get_required_documents',
        'get_application_fee',
        'get_profile_status',
        'get_fines',
        'get_licenses',
        'get_available_tests',
        'get_appointment_slots',
        'get_current_appointments',
        'get_test_results',
        'start_payment',
        'book_appointment',
        'reschedule_appointment',
        'cancel_appointment',
        'submit_documents_for_review',
    ];

    /** @var list<string> Read-only actions that may run without explicit confirmation. */
    public const READ_ONLY_ACTIONS = [
        'get_application_status',
        'get_application_next_step',
        'get_required_documents',
        'get_application_fee',
        'get_profile_status',
        'get_fines',
        'get_licenses',
        'get_available_tests',
        'get_appointment_slots',
        'get_current_appointments',
        'get_test_results',
    ];

    /** @var list<string> */
    public const ALLOWED_PROPOSED_ACTIONS = [
        'create_application',
        'get_application_status',
        'get_application_next_step',
        'get_required_documents',
        'get_application_fee',
        'get_profile_status',
        'submit_documents_for_review',
        'start_payment',
        'get_available_tests',
        'get_appointment_slots',
        'book_appointment',
        'reschedule_appointment',
        'cancel_appointment',
        'get_test_results',
        'get_licenses',
        'get_fines',
    ];

    /** @var list<string> */
    public const ADMIN_ONLY_ACTIONS = [
        'approve_document',
        'reject_document',
        'record_test_result',
        'issue_license',
        'block_license',
        'unblock_license',
        'create_fine',
        'update_fine',
        'manage_users',
        'manage_roles',
        'manage_permissions',
        'manage_settings',
        'view_audit_logs',
        'admin_report',
    ];

    /** @var list<string> */
    public const ADMIN_INTENTS = [
        'approve_document',
        'reject_document',
        'record_test_result',
        'issue_license',
        'block_license',
        'unblock_license',
        'create_fine',
        'manage_users',
        'admin_action',
    ];

    /**
     * @return list<string>
     */
    public static function validIntentValues(): array
    {
        return array_map(
            static fn (AgentIntent $intent) => $intent->value,
            AgentIntent::cases()
        );
    }

    public static function isAdminOnlyAction(string $actionName): bool
    {
        return in_array($actionName, self::ADMIN_ONLY_ACTIONS, true);
    }

    public static function isAllowedProposedAction(string $actionName): bool
    {
        return in_array($actionName, self::ALLOWED_PROPOSED_ACTIONS, true);
    }

    public static function isPhase9bExecutable(string $actionName): bool
    {
        return in_array($actionName, self::PHASE_9B_EXECUTABLE_ACTIONS, true);
    }

    public static function isReadOnlyAction(string $actionName): bool
    {
        return in_array($actionName, self::READ_ONLY_ACTIONS, true)
            || AgentWorkflowActionMap::isReadOnly($actionName);
    }

    public static function isMutatingAction(string $actionName): bool
    {
        return AgentWorkflowActionMap::isMutating($actionName);
    }

    public static function messageLooksAdminRelated(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        $patterns = [
            'approve document',
            'reject document',
            'issue license',
            'block license',
            'unblock license',
            'record test result',
            'create fine',
            'audit log',
            'manage user',
            'manage role',
            'الموافقة على المستند',
            'وافقلي على وثائقي',
            'وافق على وثائق',
            'رفض المستند',
            'إصدار الرخصة',
            'حظر الرخصة',
            'تسجيل نتيجة',
            'ثبتلي النتيجة',
            'ثبت النتيجة',
            'إنشاء مخالفة',
            'سجل التدقيق',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
