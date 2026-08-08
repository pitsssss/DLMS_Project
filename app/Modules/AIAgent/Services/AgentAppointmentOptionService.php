<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\AppointmentStatus;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentApplicationTextSelector;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Appointments\Services\TestProgressionService;
use Illuminate\Support\Collection;

/**
 * Loads real appointment/slot options for pending-workflow continuation.
 */
class AgentAppointmentOptionService
{
    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly TestProgressionService $progression,
        private readonly AgentSelectionTokenService $selectionTokens,
    ) {}

    /**
     * @return Collection<int, AppointmentSlot>
     */
    public function availableSlotsForApplication(User $citizen, LicenseApplication $application): Collection
    {
        $application->loadMissing('serviceType');
        $bookable = $this->progression->resolveBookableTestType($application);
        if ($bookable === null) {
            return collect();
        }

        try {
            return $this->appointments->listAvailableSlots($bookable->id)->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @return Collection<int, AppointmentSlot>
     */
    public function availableSlotsForAppointment(User $citizen, TestAppointment $appointment): Collection
    {
        $testTypeId = (int) $appointment->test_type_id;
        if ($testTypeId < 1) {
            return collect();
        }

        try {
            return $this->appointments->listAvailableSlots($testTypeId)
                ->filter(fn (AppointmentSlot $slot): bool => (int) $slot->id !== (int) $appointment->appointment_slot_id)
                ->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @return Collection<int, TestAppointment>
     */
    public function bookedAppointmentsForCitizen(User $citizen, ?int $applicationId = null): Collection
    {
        $query = TestAppointment::query()
            ->where('citizen_id', $citizen->id)
            ->where('status', AppointmentStatus::Booked->value)
            ->with(['testType', 'appointmentSlot.appointmentCenter', 'application.licenseType', 'application.serviceType'])
            ->orderByDesc('id');

        if ($applicationId !== null) {
            $query->where('application_id', $applicationId);
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, AppointmentSlot>  $slots
     * @return list<array<string, mixed>>
     */
    public function buildSlotButtons(
        User $citizen,
        AIAgentSession $session,
        Collection $slots,
        int $applicationId,
        string $workflowId,
        string $intent,
        int $limit = 8,
    ): array {
        $ttl = (int) config('ai.agent.selection_token_ttl_seconds', 1800);

        return $slots->take($limit)->map(function (AppointmentSlot $slot) use ($citizen, $session, $applicationId, $workflowId, $intent, $ttl): array {
            $date = $slot->date?->format('Y-m-d') ?? '';
            $time = (string) ($slot->start_time ?? '');
            $center = (string) ($slot->appointmentCenter?->name ?? '');
            $label = trim("{$date} {$time}".($center !== '' ? " — {$center}" : ''));

            return [
                'label' => $label !== '' ? $label : ('#'.$slot->id),
                'date' => $date,
                'time' => $time,
                'center' => $center,
                'selection_token' => $this->selectionTokens->issue(
                    $citizen,
                    $session,
                    AgentSelectionTokenService::PURPOSE_APPOINTMENT_SLOT,
                    $applicationId,
                    null,
                    $ttl,
                    $workflowId,
                    $intent,
                    (int) $slot->id,
                    null
                ),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, TestAppointment>  $appointments
     * @return list<array<string, mixed>>
     */
    public function buildAppointmentButtons(
        User $citizen,
        AIAgentSession $session,
        Collection $appointments,
        string $workflowId,
        string $intent,
    ): array {
        $ttl = (int) config('ai.agent.selection_token_ttl_seconds', 1800);

        return $appointments->map(function (TestAppointment $appointment) use ($citizen, $session, $workflowId, $intent, $ttl): array {
            $appointment->loadMissing(['testType', 'appointmentSlot']);
            $date = $appointment->appointmentSlot?->date?->format('Y-m-d')
                ?? $appointment->scheduled_at?->format('Y-m-d')
                ?? '';
            $time = (string) ($appointment->appointmentSlot?->start_time
                ?? $appointment->scheduled_at?->format('H:i')
                ?? '');
            $testName = (string) ($appointment->testType?->name ?? '');
            $label = trim(($testName !== '' ? $testName.' — ' : '')."{$date} {$time}");

            return [
                'label' => $label !== '' ? $label : ('#'.$appointment->id),
                'date' => $date,
                'time' => $time,
                'test_type' => $testName,
                'status' => $appointment->status instanceof AppointmentStatus
                    ? $appointment->status->value
                    : (string) $appointment->status,
                'selection_token' => $this->selectionTokens->issue(
                    $citizen,
                    $session,
                    AgentSelectionTokenService::PURPOSE_APPOINTMENT,
                    (int) $appointment->application_id,
                    null,
                    $ttl,
                    $workflowId,
                    $intent,
                    null,
                    (int) $appointment->id
                ),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, AppointmentSlot>  $slots Ordered as shown.
     * @return array{status: string, slot_id?: int, matched_ids?: list<int>}
     */
    public function resolveSlotFromText(string $message, Collection $slots): array
    {
        $normalized = AgentApplicationTextSelector::normalizeDigits(
            mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message))
        );
        if ($normalized === '' || $slots->isEmpty()) {
            return ['status' => 'ambiguous'];
        }

        $ordered = $slots->values();
        $ordinal = $this->matchOrdinal($normalized, $ordered->count());
        if ($ordinal !== null) {
            $slot = $ordered->get($ordinal);
            if ($slot instanceof AppointmentSlot) {
                return ['status' => 'matched', 'slot_id' => (int) $slot->id];
            }
        }

        if (preg_match('/(?:رقم|slot|#)\s*(\d{1,10})/u', $normalized, $m)
            || preg_match('/^(\d{1,10})$/u', $normalized, $m)) {
            $id = (int) $m[1];
            $ids = $ordered->pluck('id')->map(fn ($v) => (int) $v)->all();
            if (in_array($id, $ids, true)) {
                return ['status' => 'matched', 'slot_id' => $id];
            }
        }

        $dateMatches = $ordered->filter(function (AppointmentSlot $slot) use ($normalized): bool {
            $date = $slot->date?->format('Y-m-d') ?? '';
            $time = (string) ($slot->start_time ?? '');

            return ($date !== '' && str_contains($normalized, $date))
                || ($time !== '' && str_contains($normalized, mb_substr($time, 0, 5)));
        })->values();

        if ($dateMatches->count() === 1) {
            return ['status' => 'matched', 'slot_id' => (int) $dateMatches->first()->id];
        }

        if ($dateMatches->count() > 1) {
            return [
                'status' => 'ambiguous',
                'matched_ids' => $dateMatches->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ];
        }

        return ['status' => 'ambiguous'];
    }

    /**
     * @param  Collection<int, TestAppointment>  $appointments
     * @return array{status: string, appointment_id?: int, matched_ids?: list<int>}
     */
    public function resolveAppointmentFromText(string $message, Collection $appointments): array
    {
        $normalized = AgentApplicationTextSelector::normalizeDigits(
            mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message))
        );
        if ($normalized === '' || $appointments->isEmpty()) {
            return ['status' => 'ambiguous'];
        }

        $ordered = $appointments->values();
        $ordinal = $this->matchOrdinal($normalized, $ordered->count());
        if ($ordinal !== null) {
            $item = $ordered->get($ordinal);
            if ($item instanceof TestAppointment) {
                return ['status' => 'matched', 'appointment_id' => (int) $item->id];
            }
        }

        if (preg_match('/(?:رقم|موعد|#)\s*(\d{1,10})/u', $normalized, $m)
            || preg_match('/^(\d{1,10})$/u', $normalized, $m)) {
            $id = (int) $m[1];
            $ids = $ordered->pluck('id')->map(fn ($v) => (int) $v)->all();
            if (in_array($id, $ids, true)) {
                return ['status' => 'matched', 'appointment_id' => $id];
            }
        }

        return ['status' => 'ambiguous'];
    }

    public function slotPrompt(bool $empty): string
    {
        if ($empty) {
            return AgentTranslator::message('ai_agent.appointments.slots.none');
        }

        return AgentTranslator::message('ai_agent.appointments.slots.choose');
    }

    public function appointmentPrompt(bool $empty): string
    {
        if ($empty) {
            return AgentTranslator::message('ai_agent.appointments.choose.none');
        }

        return AgentTranslator::message('ai_agent.appointments.choose.select');
    }

    private function matchOrdinal(string $normalized, int $count): ?int
    {
        $map = [
            'الاول' => 0, 'الأول' => 0, 'اول' => 0, 'اول واحد' => 0, 'first' => 0, '1' => 0,
            'الثاني' => 1, 'تاني' => 1, 'second' => 1, '2' => 1,
            'الثالث' => 2, 'تالت' => 2, 'third' => 2, '3' => 2,
        ];

        foreach ($map as $phrase => $index) {
            if ($normalized === mb_strtolower($phrase) && $index < $count) {
                return $index;
            }
        }

        return null;
    }
}
