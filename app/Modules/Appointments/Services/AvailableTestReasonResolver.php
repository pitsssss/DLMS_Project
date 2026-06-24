<?php

namespace App\Modules\Appointments\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Modules\Appointments\Support\AvailableTestReasonCode;
use App\Support\CitizenMessageTranslator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class AvailableTestReasonResolver
{
    /**
     * @param  Collection<int, TestType>  $requiredTestTypes
     * @param  list<int>  $passedTestTypeIds
     * @return array{
     *     is_available: bool,
     *     reason_code: ?string,
     *     reason: ?string,
     *     next_action_label: ?string
     * }
     */
    public function resolve(
        LicenseApplication $application,
        TestType $testType,
        Collection $requiredTestTypes,
        ?TestType $bookableTestType,
        array $passedTestTypeIds,
        int $attemptsCount,
    ): array {
        if ($bookableTestType !== null && $bookableTestType->id === $testType->id) {
            return [
                'is_available' => true,
                'reason_code' => null,
                'reason' => null,
                'next_action_label' => CitizenMessageTranslator::get('messages.tests.availability.next_action_book'),
            ];
        }

        $reasonCode = $this->determineReasonCode(
            $application,
            $testType,
            $requiredTestTypes,
            $passedTestTypeIds,
            $attemptsCount,
        );

        return [
            'is_available' => false,
            'reason_code' => $reasonCode,
            'reason' => $this->messageFor($reasonCode, $application, $testType, $requiredTestTypes, $passedTestTypeIds),
            'next_action_label' => null,
        ];
    }

    /**
     * @param  Collection<int, TestType>  $requiredTestTypes
     * @param  list<int>  $passedTestTypeIds
     */
    private function determineReasonCode(
        LicenseApplication $application,
        TestType $testType,
        Collection $requiredTestTypes,
        array $passedTestTypeIds,
        int $attemptsCount,
    ): string {
        $status = $application->status instanceof ApplicationStatus
            ? $application->status
            : ApplicationStatus::tryFrom((string) $application->status) ?? ApplicationStatus::Draft;

        if ($status === ApplicationStatus::LicenseIssued) {
            return AvailableTestReasonCode::LICENSE_ALREADY_ISSUED;
        }

        if ($status === ApplicationStatus::Approved) {
            return in_array($testType->id, $passedTestTypeIds, true)
                ? AvailableTestReasonCode::ALREADY_PASSED
                : AvailableTestReasonCode::APPLICATION_ALREADY_APPROVED;
        }

        if (in_array($status, [ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true)) {
            return AvailableTestReasonCode::APPLICATION_BLOCKED_OR_CANCELLED;
        }

        if ($status === ApplicationStatus::AdministrativeReview) {
            if ((int) $application->current_test_type_id === $testType->id
                && $attemptsCount >= $testType->max_attempts) {
                return AvailableTestReasonCode::MAX_ATTEMPTS_REACHED;
            }

            return AvailableTestReasonCode::APPLICATION_BLOCKED_OR_CANCELLED;
        }

        if (in_array($testType->id, $passedTestTypeIds, true)) {
            return AvailableTestReasonCode::ALREADY_PASSED;
        }

        if ($attemptsCount >= $testType->max_attempts) {
            return AvailableTestReasonCode::MAX_ATTEMPTS_REACHED;
        }

        $appointmentReason = $this->appointmentBlockingReason($application, $testType);
        if ($appointmentReason !== null) {
            return $appointmentReason;
        }

        if (! $this->applicationAllowsBooking($status)) {
            return $status === ApplicationStatus::PaymentPending
                ? AvailableTestReasonCode::PAYMENT_NOT_COMPLETED
                : AvailableTestReasonCode::APPLICATION_NOT_READY_FOR_TESTING;
        }

        if ($status === ApplicationStatus::WaitingRetest
            && (int) $application->current_test_type_id !== $testType->id) {
            $priorUnpassed = $this->firstUnpassedPriorTest($testType, $requiredTestTypes, $passedTestTypeIds);

            return $priorUnpassed !== null
                ? AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED
                : AvailableTestReasonCode::NOT_CURRENT_TEST;
        }

        $priorUnpassed = $this->firstUnpassedPriorTest($testType, $requiredTestTypes, $passedTestTypeIds);
        if ($priorUnpassed !== null) {
            return AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED;
        }

        return AvailableTestReasonCode::NOT_CURRENT_TEST;
    }

    private function appointmentBlockingReason(LicenseApplication $application, TestType $testType): ?string
    {
        $appointment = TestAppointment::query()
            ->where('application_id', $application->id)
            ->where('test_type_id', $testType->id)
            ->where('status', AppointmentStatus::Booked)
            ->orderByDesc('id')
            ->first();

        if ($appointment === null) {
            return null;
        }

        if ($appointment->testResult()->exists()) {
            return null;
        }

        if ($appointment->scheduled_at !== null && $appointment->scheduled_at->lte(Carbon::now())) {
            return AvailableTestReasonCode::WAITING_RESULT;
        }

        return AvailableTestReasonCode::ACTIVE_APPOINTMENT_EXISTS;
    }

    /**
     * @param  Collection<int, TestType>  $requiredTestTypes
     * @param  list<int>  $passedTestTypeIds
     */
    private function firstUnpassedPriorTest(
        TestType $testType,
        Collection $requiredTestTypes,
        array $passedTestTypeIds,
    ): ?TestType {
        foreach ($requiredTestTypes as $required) {
            if ($required->sequence_order >= $testType->sequence_order) {
                break;
            }

            if (! in_array($required->id, $passedTestTypeIds, true)) {
                return $required;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, TestType>  $requiredTestTypes
     * @param  list<int>  $passedTestTypeIds
     */
    private function messageFor(
        string $reasonCode,
        LicenseApplication $application,
        TestType $testType,
        Collection $requiredTestTypes,
        array $passedTestTypeIds,
    ): string {
        $key = 'messages.tests.availability.'.$reasonCode;

        if ($reasonCode === AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED) {
            $previous = $this->firstUnpassedPriorTest($testType, $requiredTestTypes, $passedTestTypeIds);

            return CitizenMessageTranslator::get($key, [
                'previous_test' => $previous?->name ?? CitizenMessageTranslator::get('messages.tests.availability.previous_test_fallback'),
                'current_test' => $testType->name,
            ]);
        }

        return CitizenMessageTranslator::get($key);
    }

    private function applicationAllowsBooking(ApplicationStatus $status): bool
    {
        return in_array($status, [
            ApplicationStatus::AppointmentPending,
            ApplicationStatus::InTesting,
            ApplicationStatus::WaitingRetest,
        ], true);
    }
}
