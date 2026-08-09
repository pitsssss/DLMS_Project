<?php

namespace App\Modules\Appointments\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Exceptions\ApiException;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Repositories\AppointmentSlotRepository;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Support\AppointmentNotificationCopy;
use App\Modules\Notifications\Support\NotificationEventKey;
use App\Services\AuditLogService;
use App\Support\BusinessClock;
use App\Support\CitizenCatalogLabel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentSlotRepository $slots,
        private readonly TestProgressionService $progression,
        private readonly AuditLogService $auditLogs,
        private readonly BusinessClock $clock,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function availableTestsForApplication(User $citizen, int $applicationId): array
    {
        $application = $this->requireOwnedApplication($citizen, $applicationId);
        $application->loadMissing('serviceType');

        if (! \App\Modules\Applications\Support\ServiceWorkflow::requiresTests($application->serviceType?->code)) {
            return [
                'blocked' => true,
                'message' => \App\Support\CitizenMessageTranslator::get('messages.appointments.tests_not_required'),
                'tests' => [],
            ];
        }

        return [
            'blocked' => false,
            'message' => null,
            'tests' => $this->progression->availableTestsPayload($application),
        ];
    }

    /**
     * @return Collection<int, AppointmentSlot>
     */
    public function listAvailableSlots(int $testTypeId, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $testType = TestType::query()->whereKey($testTypeId)->where('is_active', true)->first();
        if ($testType === null) {
            throw new ApiException('messages.appointments.test_type_not_found', 404);
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
            $application->loadMissing('serviceType');

            if (! \App\Modules\Applications\Support\ServiceWorkflow::requiresTests($application->serviceType?->code)) {
                throw new ApiException('messages.appointments.tests_not_required', 422);
            }

            $bookable = $this->progression->resolveBookableTestType($application);
            if ($bookable === null) {
                throw new ApiException('messages.appointments.no_test_available', 422);
            }

            $this->progression->assertCanBook($application, $bookable);

            $slot = AppointmentSlot::query()
                ->whereKey($appointmentSlotId)
                ->lockForUpdate()
                ->with(['testType', 'appointmentCenter'])
                ->first();

            $businessToday = $this->clock->now()->toDateString();

            if ($slot === null
                || ! $slot->is_active
                || $slot->date->format('Y-m-d') < $businessToday
                || $slot->booked_count >= $slot->capacity) {
                throw new ApiException('messages.appointments.slot_unavailable', 422);
            }

            if ($slot->test_type_id !== $bookable->id) {
                throw new ApiException('messages.appointments.previous_test_not_passed', 422, [], [
                    'previous_test' => CitizenCatalogLabel::testType((string) $bookable->code, $bookable->name),
                    'current_test' => CitizenCatalogLabel::testType(
                        (string) ($slot->testType?->code ?? ''),
                        $slot->testType?->name ?? ''
                    ),
                ]);
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
                    __('messages.appointments.note_booked')
                );
            } else {
                $application->save();
            }

            $fresh = $appointment->fresh(['appointmentSlot.appointmentCenter', 'testType']);
            $this->notifyAppointment($fresh, NotificationType::AppointmentBooked, $citizen->id);

            return $fresh;
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
                throw new ApiException('messages.appointments.not_found', 404);
            }

            if ($appointment->status !== AppointmentStatus::Booked) {
                throw new ApiException('messages.appointments.only_booked_reschedule', 422);
            }

            $oldSlotId = (int) $appointment->appointment_slot_id;
            if ($newAppointmentSlotId === $oldSlotId) {
                return $appointment->fresh(['appointmentSlot.appointmentCenter', 'testType']);
            }

            $slotIds = [$oldSlotId, $newAppointmentSlotId];
            sort($slotIds, SORT_NUMERIC);

            $lockedSlots = AppointmentSlot::query()
                ->whereIn('id', $slotIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $oldSlot = $lockedSlots->get($oldSlotId);
            $newSlot = $lockedSlots->get($newAppointmentSlotId);
            $businessToday = $this->clock->now()->toDateString();

            if ($newSlot === null
                || ! $newSlot->is_active
                || $newSlot->test_type_id !== $appointment->test_type_id
                || $newSlot->date->format('Y-m-d') < $businessToday
                || $newSlot->booked_count >= $newSlot->capacity) {
                throw new ApiException('messages.appointments.slot_not_available', 422);
            }

            if ($oldSlot === null) {
                throw new ApiException('messages.appointments.slot_unavailable', 422);
            }

            $oldSnapshot = $this->slotAuditSnapshot($oldSlot);

            if ($oldSlot->booked_count > 0) {
                $oldSlot->decrement('booked_count');
            }

            $newSlot->increment('booked_count');

            $scheduledAt = Carbon::parse($newSlot->date->format('Y-m-d').' '.$newSlot->start_time);

            $appointment->appointment_slot_id = $newSlot->id;
            $appointment->scheduled_at = $scheduledAt;
            $appointment->save();

            $this->auditLogs->log(
                $citizen,
                'appointment.rescheduled',
                'appointment',
                $appointment->id,
                $oldSnapshot,
                $this->slotAuditSnapshot($newSlot),
                request(),
            );

            $fresh = $appointment->fresh(['appointmentSlot.appointmentCenter', 'testType']);
            $this->notifyAppointment($fresh, NotificationType::AppointmentRescheduled, $citizen->id);

            return $fresh;
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
                throw new ApiException('messages.appointments.not_found', 404);
            }

            if ($appointment->status !== AppointmentStatus::Booked) {
                throw new ApiException('messages.appointments.only_booked_cancel', 422);
            }

            $slot = AppointmentSlot::query()->whereKey($appointment->appointment_slot_id)->lockForUpdate()->firstOrFail();
            if ($slot->booked_count > 0) {
                $slot->decrement('booked_count');
            }

            $appointment->status = AppointmentStatus::Cancelled;
            $appointment->cancelled_at = now();
            $appointment->cancellation_reason = $reason;
            $appointment->save();

            $this->auditLogs->log(
                $citizen,
                'appointment.cancelled',
                'appointment',
                $appointment->id,
                [
                    'status' => AppointmentStatus::Booked->value,
                    'appointment_slot_id' => $slot->id,
                ],
                [
                    'appointment_id' => $appointment->id,
                    'appointment_slot_id' => $slot->id,
                    'reason' => $reason,
                    'cancelled_at' => $appointment->cancelled_at?->toIso8601String(),
                    'status' => AppointmentStatus::Cancelled->value,
                ],
                request(),
            );

            $fresh = $appointment->fresh(['appointmentSlot.appointmentCenter', 'testType']);
            $this->notifyAppointment($fresh, NotificationType::AppointmentCancelled, $citizen->id);

            return $fresh;
        });
    }

    private function notifyAppointment(TestAppointment $appointment, NotificationType $type, int $citizenId): void
    {
        $eventKey = match ($type) {
            NotificationType::AppointmentRescheduled => NotificationEventKey::forAppointmentReschedule(
                $appointment->id,
                (int) $appointment->appointment_slot_id,
                $appointment->scheduled_at
            ),
            default => NotificationEventKey::forAppointment($type, $appointment->id),
        };

        $this->notifications->notify(
            $citizenId,
            $type,
            [
                'application_id' => $appointment->application_id,
                'appointment_id' => $appointment->id,
                'test_type_id' => $appointment->test_type_id,
            ],
            AppointmentNotificationCopy::placeholders($appointment),
            $eventKey
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function slotAuditSnapshot(AppointmentSlot $slot): array
    {
        return [
            'appointment_slot_id' => $slot->id,
            'date' => $slot->date->format('Y-m-d'),
            'start_time' => (string) $slot->start_time,
            'end_time' => (string) $slot->end_time,
            'test_type_id' => $slot->test_type_id,
            'appointment_center_id' => $slot->appointment_center_id,
        ];
    }

    private function requireOwnedApplication(User $citizen, int $applicationId): LicenseApplication
    {
        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('messages.applications.not_found', 404);
        }

        return $application;
    }
}
