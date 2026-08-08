<?php

namespace App\Modules\AIAgent\Support;

use App\Exceptions\ApiException;

class AgentActionArgumentValidator
{
    /**
     * @return list<string>
     */
    public static function requiredArguments(string $actionName): array
    {
        return match ($actionName) {
            'book_appointment' => ['application_id', 'appointment_slot_id'],
            'start_payment' => ['application_id'],
            'submit_documents_for_review' => ['application_id'],
            'get_application_status' => ['application_id'],
            'get_application_next_step' => ['application_id'],
            'get_required_documents' => ['application_id'],
            'get_application_fee' => ['application_id'],
            'get_test_results' => ['application_id'],
            'get_appointment_slots' => ['application_id'],
            'get_current_appointments' => ['application_id'],
            'get_available_tests' => ['application_id'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function missingArguments(string $actionName, array $arguments): array
    {
        $missing = [];

        foreach (self::requiredArguments($actionName) as $key) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @throws ApiException
     */
    public static function assertComplete(string $actionName, array $arguments): void
    {
        $missing = self::missingArguments($actionName, $arguments);
        if ($missing === []) {
            return;
        }

        throw new ApiException(
            'لا يمكن المتابعة قبل اكتمال البيانات المطلوبة.',
            422,
            ['missing_arguments' => $missing],
            [],
            'ACTION_ARGUMENTS_INCOMPLETE',
            ['missing_arguments' => $missing]
        );
    }
}
