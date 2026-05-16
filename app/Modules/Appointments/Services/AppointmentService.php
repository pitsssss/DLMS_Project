<?php

namespace App\Modules\Appointments\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ApplicationStatus;
use App\Exceptions\ApiException;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Repositories\AppointmentSlotRepository;
use App\Modules\Applications\Repositories\ApplicationRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentSlotRepository $slots,
        private readonly TestProgressionService $progression
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function availableTestsForApplication(User $citizen, int $applicationId): array
    {
        $application = $this->requireOwnedApplication($citizen, $applicationId);

        return $this->progression->availableTestsPayload($application);
    }

    /**
     * @return Collection<int, AppointmentSlot>
     */
    public function listAvailableSlots(int $testTypeId, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $testType = TestType::query()->whereKey($testTypeId)->where('is_active', true)->first();
        if ($testType === null) {
            throw new ApiException('Test type not found.', 404);
        }

        return $this->slots->listAvailable($testTypeId, $fromDate, $toDate);
    }

    /**
     * @return Collection<int, TestAppointment>
     */
    public function listApplicationAppointments(User $citizen, int $applicationId): Collection
    {
        $application = $this->requireOwnedApplication($citizen, $applicationId);

        return $this->appointments->listForApplication($application->id, $citizen->id);
    }

    public function book(User $citizen, int $applicationId, int $appointmentSlotId): TestAppointment
    {
        $application = $this->requireOwnedApplication($citizen, $applicationId);

        return DB::transaction(function () use ($citizen, $application, $appointmentSlotId) {
            $application = LicenseApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            $bookable = $this->progression->resolveBookableTestType($application);
            if ($bookable === null) {
                throw new ApiException('No test is available to book for this application.', 422);
            }

            $this->progression->assertCanBook($application, $bookable);

            $slot = $this->slots->findAvailableForBooking($appointmentSlotId, $bookable->id);
            if ($slot === null) {
                throw new ApiException('This appointment slot is not available.', 422);
            }

            $scheduledAt = Carbon::parse($slot->date->format('Y-m-d').' '.$slot->start_time);

            $appointment = TestAppointment::query()->create([
                'application_id' => $application->id,
                'citizen_id' => $citizen->id,
                'appointment_slot_id' => $slot->id,
                'test_type_id' => $bookable->id,
                'status' => AppointmentStatus::Booked,
                'scheduled_at' => $scheduledAt,
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);

            $slot->increment('booked_count');

            $application->current_test_type_id = $bookable->id;

            if ($application->status === ApplicationStatus::AppointmentPending) {
                $this->applications->transitionStatus(
                    $application,
                    ApplicationStatus::InTesting,
                    $citizen,
                    'Test appointment booked. Application is now in testing.'
                );
            } else {
                $application->save();
            }

            return $appointment->fresh(['appointmentSlot', 'testType']);
        });
    }

    public function reschedule(User $citizen, int $appointmentId, int $newAppointmentSlotId): TestAppointment
    {
        return DB::transaction(function () use ($citizen, $appointmentId, $newAppointmentSlotId) {
            $appointment = TestAppointment::query()
                ->whereKey($appointmentId)
                ->where('citizen_id', $citizen->id)
                ->lockForUpdate()
                ->first();

            if ($appointment === null) {
                throw new ApiException('Appointment not found.', 404);
            }

            if ($appointment->status !== AppointmentStatus::Booked) {
                throw new ApiException('Only booked appointments can be rescheduled.', 422);
            }

            $newSlot = $this->slots->findAvailableForBooking($newAppointmentSlotId, $appointment->test_type_id);
            if ($newSlot === null) {
                throw new ApiException('The selected appointment slot is not available.', 422);
            }

            if ($newSlot->id === $appointment->appointment_slot_id) {
                return $appointment->fresh(['appointmentSlot', 'testType']);
            }

            $oldSlot = AppointmentSlot::query()->whereKey($appointment->appointment_slot_id)->lockForUpdate()->firstOrFail();
            if ($oldSlot->booked_count > 0) {
                $oldSlot->decrement('booked_count');
            }

            $newSlot->increment('booked_count');

            $scheduledAt = Carbon::parse($newSlot->date->format('Y-m-d').' '.$newSlot->start_time);

            $appointment->appointment_slot_id = $newSlot->id;
            $appointment->scheduled_at = $scheduledAt;
            $appointment->save();

            return $appointment->fresh(['appointmentSlot', 'testType']);
        });
    }

    public function cancel(User $citizen, int $appointmentId, ?string $reason = null): TestAppointment
    {
        return DB::transaction(function () use ($citizen, $appointmentId, $reason) {
            $appointment = TestAppointment::query()
                ->whereKey($appointmentId)
                ->where('citizen_id', $citizen->id)
                ->lockForUpdate()
                ->first();

            if ($appointment === null) {
                throw new ApiException('Appointment not found.', 404);
            }

            if ($appointment->status !== AppointmentStatus::Booked) {
                throw new ApiException('Only booked appointments can be cancelled.', 422);
            }

            $slot = AppointmentSlot::query()->whereKey($appointment->appointment_slot_id)->lockForUpdate()->firstOrFail();
            if ($slot->booked_count > 0) {
                $slot->decrement('booked_count');
            }

            $appointment->status = AppointmentStatus::Cancelled;
            $appointment->cancelled_at = now();
            $appointment->cancellation_reason = $reason;
            $appointment->save();

            return $appointment->fresh(['appointmentSlot', 'testType']);
        });
    }

    private function requireOwnedApplication(User $citizen, int $applicationId): LicenseApplication
    {
        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('Application not found.', 404);
        }

        return $application;
    }
}
