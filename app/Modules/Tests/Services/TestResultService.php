<?php

namespace App\Modules\Tests\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ApplicationStatus;
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
use App\Services\AuditLogService;
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
            throw new ApiException('Application not found.', 404);
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
                throw new ApiException('Test appointment not found.', 404);
            }

            if ($appointment->status !== AppointmentStatus::Booked) {
                throw new ApiException('A result can only be recorded for a booked appointment.', 422);
            }

            if ($appointment->testResult()->exists()) {
                throw new ApiException('A result has already been recorded for this appointment.', 422);
            }

            $application = LicenseApplication::query()
                ->whereKey($appointment->application_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($application->status, [ApplicationStatus::InTesting, ApplicationStatus::WaitingRetest], true)) {
                throw new ApiException('The application is not in a testable status.', 422);
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
                default => throw new ApiException('Invalid test result.', 422),
            };

            $this->auditLogs->log(
                $employee,
                'test_result.recorded',
                'test_result',
                $testResult->id,
                null,
                ['result' => $result->value, 'test_type_id' => $testType->id, 'application_id' => $application->id]
            );

            $this->notifications->sendToUser(
                $application->citizen_id,
                'Test result recorded',
                'Your '.$testType->name.' result has been recorded: '.$result->value.'.',
                'test_result.'.$result->value,
                ['application_id' => $application->id, 'test_result_id' => $testResult->id]
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
                'All required tests passed. Application approved for license issuance.'
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
                'Test passed. Continue with remaining tests.'
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
                'Maximum test attempts reached. Sent to administrative review.'
            );

            return;
        }

        $application->current_test_type_id = $testType->id;
        $application->save();

        $this->applications->transitionStatus(
            $application,
            ApplicationStatus::WaitingRetest,
            $employee,
            'Test failed. Citizen may book a retake.'
        );
    }

    private function handleNoShow(LicenseApplication $application, TestType $testType, User $employee): void
    {
        $this->handleFailed($application, $testType, $employee);
    }
}
