<?php

namespace App\Modules\Dashboard\Support;

use App\Models\TestAppointment;
use App\Models\User;
use App\Modules\Tests\Services\TestResultService;

final class DashboardTestAppointmentActions
{
    /**
     * @return array{can_record_result: bool, can_view_application: bool}
     */
    public static function for(TestAppointment $appointment, User $actor, TestResultService $tests): array
    {
        $canViewApplication = $actor->hasPermission('view_applications')
            || $actor->hasPermission('manage_applications');

        return [
            'can_record_result' => $actor->hasPermission('record_test_result')
                && $tests->isAppointmentRecordable($appointment),
            'can_view_application' => $canViewApplication && $appointment->application_id !== null,
        ];
    }
}
