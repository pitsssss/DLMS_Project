<?php

namespace App\Modules\AIAgent\Support;

class AgentWorkflowActionMap
{
    /** @var list<string> */
    public const READ_ONLY_ACTIONS = [
        'get_application_status',
        'get_application_next_step',
        'get_required_documents',
        'get_application_fee',
        'get_payment_status',
        'get_available_tests',
        'get_appointment_slots',
        'get_test_results',
        'get_licenses',
        'get_license_details',
        'get_fines',
        'get_profile_status',
        'get_notifications',
    ];

    /** @var list<string> */
    public const MUTATING_ACTIONS = [
        'create_application',
        'start_payment',
        'submit_documents_for_review',
        'book_appointment',
        'reschedule_appointment',
        'cancel_appointment',
        'renew_license',
        'request_license_replacement',
        'request_unblock',
    ];

    public static function isReadOnly(string $actionName): bool
    {
        return in_array($actionName, self::READ_ONLY_ACTIONS, true);
    }

    public static function isMutating(string $actionName): bool
    {
        return in_array($actionName, self::MUTATING_ACTIONS, true);
    }

    public static function requiresConfirmation(string $actionName): bool
    {
        return self::isMutating($actionName);
    }
}
