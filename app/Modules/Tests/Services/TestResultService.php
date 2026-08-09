<?php

namespace App\Modules\Tests\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Enums\TestResultStatus;
use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Appointments\Services\TestProgressionService;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Support\NotificationEventKey;
use App\Services\AuditLogService;
use App\Support\RecipientNotificationTranslator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TestResultService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly TestProgressionService $progression,
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications
    ) {}

    /**
     * @return Collection<int, TestResult>
     */
    public function listForApplication(User $citizen, int $applicationId): Collection
    {
        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('messages.applications.not_found', 404);
        }

        return TestResult::query()
            ->where('application_id', $application->id)
            ->with(['testType', 'testAppointment', 'recordedBy'])
            ->orderBy('recorded_at')
            ->get();
    }

    public function recordForAppointment(User $employee, int $appointmentId, TestResultStatus $result, ?string $notes = null): TestResult
    {
        return DB::transaction(function () use ($employee, $appointmentId, $result, $notes) {
            $appointment = TestAppointment::query()
                ->whereKey($appointmentId)
                ->lockForUpdate()
                ->first();

            if ($appointment === null) {
                throw new ApiException('messages.tests.appointment_not_found', 404);
            }

            if ($appointment->status !== AppointmentStatus::Booked) {
                throw new ApiException('messages.tests.only_booked_result', 422);
            }

            if ($appointment->testResult()->exists()) {
                throw new ApiException('messages.tests.already_recorded', 422);
            }

            $application = LicenseApplication::query()
                ->whereKey($appointment->application_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($application->status, [ApplicationStatus::InTesting, ApplicationStatus::WaitingRetest], true)) {
                throw new ApiException('messages.tests.not_testable_status', 422);
            }

            $attemptNumber = TestResult::query()
                ->where('application_id', $application->id)
                ->where('test_type_id', $appointment->test_type_id)
                ->count() + 1;

            $testResult = TestResult::query()->create([
                'application_id' => $application->id,
                'test_appointment_id' => $appointment->id,
                'test_type_id' => $appointment->test_type_id,
                'result' => $result,
                'attempt_number' => $attemptNumber,
                'notes' => $notes,
                'recorded_by' => $employee->id,
                'recorded_at' => now(),
            ]);

            $appointment->status = $result === TestResultStatus::NoShow
                ? AppointmentStatus::NoShow
                : AppointmentStatus::Completed;
            $appointment->save();

            $testType = TestType::query()->findOrFail($appointment->test_type_id);

            match ($result) {
                TestResultStatus::Passed => $this->handlePassed($application, $testType, $employee),
                TestResultStatus::Failed => $this->handleFailed($application, $testType, $employee),
                TestResultStatus::NoShow => $this->handleNoShow($application, $testType, $employee),
                default => throw new ApiException('messages.tests.invalid_result', 422),
            };

            $this->auditLogs->log(
                $employee,
                'test_result.recorded',
                'test_result',
                $testResult->id,
                null,
                ['result' => $result->value, 'test_type_id' => $testType->id, 'application_id' => $application->id]
            );

            $locale = RecipientNotificationTranslator::localeForUserId((int) $application->citizen_id);
            $type = NotificationType::fromTestResultStatus($result);

            $this->notifications->notify(
                (int) $application->citizen_id,
                $type,
                ['application_id' => $application->id, 'test_result_id' => $testResult->id],
                [
                    'test_name' => $testType->name,
                    'result' => RecipientNotificationTranslator::get(
                        'messages.tests.result_'.$result->value,
                        [],
                        $locale
                    ),
                ],
                NotificationEventKey::forTestResult($type, $testResult->id)
            );

            return $testResult->fresh(['testType', 'testAppointment', 'recordedBy']);
        });
    }

    private function handlePassed(LicenseApplication $application, TestType $testType, User $employee): void
    {
        if ($this->progression->allRequiredTestsPassed($application)) {
            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::Approved,
                $employee,
                __('messages.tests.note_all_passed')
            );

            return;
        }

        $next = $this->progression->nextTestTypeAfterPass($testType);
        $application->current_test_type_id = $next?->id;
        $application->save();

        if ($application->status !== ApplicationStatus::InTesting) {
            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::InTesting,
                $employee,
                __('messages.tests.note_passed_continue')
            );
        }
    }

    private function handleFailed(LicenseApplication $application, TestType $testType, User $employee): void
    {
        $failedAttempts = $this->progression->attemptCount($application, $testType->id);

        if ($failedAttempts >= $testType->max_attempts) {
            $application->current_test_type_id = $testType->id;
            $application->save();

            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::AdministrativeReview,
                $employee,
                __('messages.tests.note_max_attempts')
            );

            return;
        }

        $application->current_test_type_id = $testType->id;
        $application->save();

        $this->applications->transitionStatus(
            $application,
            ApplicationStatus::WaitingRetest,
            $employee,
            __('messages.tests.note_failed_retest')
        );
    }

    private function handleNoShow(LicenseApplication $application, TestType $testType, User $employee): void
    {
        $this->handleFailed($application, $testType, $employee);
    }
}
