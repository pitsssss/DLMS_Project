<?php

namespace App\Modules\Appointments\Support;

final class AvailableTestReasonCode
{
    public const APPLICATION_NOT_READY_FOR_TESTING = 'application_not_ready_for_testing';

    public const PAYMENT_NOT_COMPLETED = 'payment_not_completed';

    public const PREVIOUS_TEST_NOT_PASSED = 'previous_test_not_passed';

    public const ALREADY_PASSED = 'already_passed';

    public const ACTIVE_APPOINTMENT_EXISTS = 'active_appointment_exists';

    public const WAITING_RESULT = 'waiting_result';

    public const MAX_ATTEMPTS_REACHED = 'max_attempts_reached';

    public const NOT_CURRENT_TEST = 'not_current_test';

    public const APPLICATION_ALREADY_APPROVED = 'application_already_approved';

    public const LICENSE_ALREADY_ISSUED = 'license_already_issued';

    public const APPLICATION_BLOCKED_OR_CANCELLED = 'application_blocked_or_cancelled';
}
