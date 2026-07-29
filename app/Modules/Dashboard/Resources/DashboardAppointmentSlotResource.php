<?php

namespace App\Modules\Dashboard\Resources;

use App\Models\AppointmentSlot;
use App\Models\User;
use App\Modules\Appointments\Support\AppointmentSlotPresenter;
use App\Modules\Appointments\Support\SlotIdentity;
use App\Modules\Dashboard\Support\DashboardAppointmentSlotActions;
use App\Support\Msg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppointmentSlot */
class DashboardAppointmentSlotResource extends JsonResource
{
    /**
     * @var array<string, mixed>
     */
    public static array $detailContext = [];

    public function toArray(Request $request): array
    {
        /** @var AppointmentSlot $slot */
        $slot = $this->resource;
        $actor = $request->user();
        /** @var AppointmentSlotPresenter $presenter */
        $presenter = app(AppointmentSlotPresenter::class);

        $base = [
            'id' => $slot->id,
            'test_type' => $presenter->testTypePayload($slot),
            'date' => $slot->date->format('Y-m-d'),
            'start_time' => SlotIdentity::normalizeTime((string) $slot->start_time),
            'end_time' => SlotIdentity::normalizeTime((string) $slot->end_time),
            'duration_minutes' => $presenter->durationMinutes($slot),
            'appointment_center' => $presenter->centerPayload($slot),
            'location' => $slot->location,
            'capacity' => (int) $slot->capacity,
            'booked_count' => (int) $slot->booked_count,
            'remaining_capacity' => $presenter->remainingCapacity($slot),
            'utilization_rate' => $presenter->utilizationRate($slot),
            'is_active' => (bool) $slot->is_active,
            'is_active_label' => Msg::get($slot->is_active ? 'appointment_slots.statuses.active' : 'appointment_slots.statuses.inactive'),
            'availability_status' => $presenter->availabilityStatus($slot),
            'availability_status_label' => $presenter->availabilityStatusLabel($slot),
            'is_past' => $presenter->isPast($slot),
            'created_at' => $slot->created_at?->toIso8601String(),
            'updated_at' => $slot->updated_at?->toIso8601String(),
            'version' => (int) $slot->version,
        ];

        if (! (self::$detailContext['details'] ?? false)) {
            $base['actions'] = $actor instanceof User
                ? DashboardAppointmentSlotActions::for($slot, $actor)
                : [];

            return $base;
        }

        $detail = array_merge($base, [
            'created_by' => $slot->createdBy ? [
                'id' => $slot->createdBy->id,
                'name' => $slot->createdBy->name,
            ] : null,
            'updated_by' => $slot->updatedBy ? [
                'id' => $slot->updatedBy->id,
                'name' => $slot->updatedBy->name,
            ] : null,
            'deactivated_at' => $slot->deactivated_at?->toIso8601String(),
            'deactivated_by' => $slot->deactivatedBy ? [
                'id' => $slot->deactivatedBy->id,
                'name' => $slot->deactivatedBy->name,
            ] : null,
            'bookings_summary' => [
                'total' => (int) ($slot->bookings_total_count ?? 0),
                'booked' => (int) ($slot->bookings_booked_count ?? 0),
                'completed' => (int) ($slot->bookings_completed_count ?? 0),
                'cancelled' => (int) ($slot->bookings_cancelled_count ?? 0),
                'no_show' => (int) ($slot->bookings_no_show_count ?? 0),
            ],
            'actions' => $actor instanceof User
                ? DashboardAppointmentSlotActions::for($slot, $actor)
                : [],
        ]);

        self::$detailContext = [];

        return $detail;
    }

    public static function detail(AppointmentSlot $slot): self
    {
        self::$detailContext = ['details' => true];

        return new self($slot);
    }

    public static function collection($resource)
    {
        self::$detailContext = [];

        return parent::collection($resource);
    }
}
