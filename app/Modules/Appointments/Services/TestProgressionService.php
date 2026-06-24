<?php

namespace App\Modules\Appointments\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ApplicationStatus;
use App\Enums\TestResultStatus;
use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use Illuminate\Database\Eloquent\Collection;

class TestProgressionService
{
    public function __construct(
        private readonly AvailableTestReasonResolver $availabilityReasons,
    ) {}
    /**
     * @return Collection<int, TestType>
     */
    public function requiredTestTypes(): Collection
    {
        return TestType::query()
            ->where('is_required', true)
            ->where('is_active', true)
            ->orderBy('sequence_order')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function passedTestTypeIds(LicenseApplication $application): array
    {
        return TestResult::query()
            ->where('application_id', $application->id)
            ->where('result', TestResultStatus::Passed)
            ->pluck('test_type_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function attemptCount(LicenseApplication $application, int $testTypeId): int
    {
        return TestResult::query()
            ->where('application_id', $application->id)
            ->where('test_type_id', $testTypeId)
            ->whereIn('result', [TestResultStatus::Failed, TestResultStatus::NoShow])
            ->count();
    }

    public function hasActiveBookedAppointment(LicenseApplication $application, int $testTypeId): bool
    {
        return TestAppointment::query()
            ->where('application_id', $application->id)
            ->where('test_type_id', $testTypeId)
            ->where('status', AppointmentStatus::Booked)
            ->exists();
    }

    public function allRequiredTestsPassed(LicenseApplication $application): bool
    {
        $requiredIds = $this->requiredTestTypes()->pluck('id')->all();
        $passedIds = $this->passedTestTypeIds($application);

        foreach ($requiredIds as $id) {
            if (! in_array($id, $passedIds, true)) {
                return false;
            }
        }

        return $requiredIds !== [];
    }

    public function resolveBookableTestType(LicenseApplication $application): ?TestType
    {
        if (! $this->applicationAllowsBooking($application)) {
            return null;
        }

        $passedIds = $this->passedTestTypeIds($application);

        if ($application->status === ApplicationStatus::WaitingRetest) {
            if ($application->current_test_type_id === null) {
                return null;
            }

            $testType = TestType::query()->find($application->current_test_type_id);
            if ($testType === null || ! $testType->is_active) {
                return null;
            }

            if ($this->hasActiveBookedAppointment($application, $testType->id)) {
                return null;
            }

            return $testType;
        }

        foreach ($this->requiredTestTypes() as $testType) {
            if (in_array($testType->id, $passedIds, true)) {
                continue;
            }

            if ($this->hasActiveBookedAppointment($application, $testType->id)) {
                return null;
            }

            return $testType;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableTestsPayload(LicenseApplication $application): array
    {
        $requiredTestTypes = $this->requiredTestTypes();
        $bookable = $this->resolveBookableTestType($application);
        $passedIds = $this->passedTestTypeIds($application);

        $items = [];
        foreach ($requiredTestTypes as $testType) {
            $attemptsCount = $this->attemptCount($application, $testType->id);
            $availability = $this->availabilityReasons->resolve(
                $application,
                $testType,
                $requiredTestTypes,
                $bookable,
                $passedIds,
                $attemptsCount,
            );

            $items[] = [
                'id' => $testType->id,
                'test_type_id' => $testType->id,
                'name' => $testType->name,
                'code' => $testType->code,
                'sequence_order' => $testType->sequence_order,
                'max_attempts' => $testType->max_attempts,
                'passed' => in_array($testType->id, $passedIds, true),
                'is_completed' => in_array($testType->id, $passedIds, true),
                'can_book' => $availability['is_available'],
                'is_available' => $availability['is_available'],
                'has_active_appointment' => $this->hasActiveBookedAppointment($application, $testType->id),
                'latest_result' => $this->latestResultPayload($application, $testType->id),
                'attempts_used' => $attemptsCount,
                'attempts_count' => $attemptsCount,
                'reason_code' => $availability['reason_code'],
                'reason' => $availability['reason'],
                'next_action_label' => $availability['next_action_label'],
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestResultPayload(LicenseApplication $application, int $testTypeId): ?array
    {
        $result = TestResult::query()
            ->where('application_id', $application->id)
            ->where('test_type_id', $testTypeId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        if ($result === null) {
            return null;
        }

        return [
            'result' => $result->result->value,
            'attempt_number' => $result->attempt_number,
            'recorded_at' => $result->recorded_at?->toIso8601String(),
        ];
    }

    public function assertCanBook(LicenseApplication $application, TestType $testType): void
    {
        if (! $this->applicationAllowsBooking($application)) {
            throw new ApiException('messages.appointments.cannot_book_status', 422);
        }

        $bookable = $this->resolveBookableTestType($application);

        if ($bookable === null || $bookable->id !== $testType->id) {
            throw new ApiException('messages.appointments.test_not_ready', 422);
        }

        foreach ($this->requiredTestTypes() as $required) {
            if ($required->sequence_order >= $testType->sequence_order) {
                break;
            }

            if (! in_array($required->id, $this->passedTestTypeIds($application), true)) {
                throw new ApiException('messages.appointments.prior_tests_required', 422);
            }
        }
    }

    public function applicationAllowsBooking(LicenseApplication $application): bool
    {
        return in_array($application->status, [
            ApplicationStatus::AppointmentPending,
            ApplicationStatus::InTesting,
            ApplicationStatus::WaitingRetest,
        ], true);
    }

    public function nextTestTypeAfterPass(TestType $passedTestType): ?TestType
    {
        return TestType::query()
            ->where('is_required', true)
            ->where('is_active', true)
            ->where('sequence_order', '>', $passedTestType->sequence_order)
            ->orderBy('sequence_order')
            ->first();
    }
}
