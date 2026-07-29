<?php

namespace App\Modules\Appointments\Support;

use App\Models\AppointmentSlot;
use App\Support\BusinessClock;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Carbon\Carbon;

final class AppointmentSlotPresenter
{
    public function __construct(
        private readonly BusinessClock $clock,
    ) {}

    public function businessToday(): string
    {
        return $this->clock->now()->toDateString();
    }

    public function isPast(AppointmentSlot $slot): bool
    {
        return $slot->date->format('Y-m-d') < $this->businessToday();
    }

    public function availabilityStatus(AppointmentSlot $slot): string
    {
        if (! $slot->is_active) {
            return 'inactive';
        }

        if ($this->isPast($slot)) {
            return 'past';
        }

        if ((int) $slot->booked_count >= (int) $slot->capacity) {
            return 'full';
        }

        return 'available';
    }

    public function availabilityStatusLabel(AppointmentSlot $slot): string
    {
        return Msg::get('appointment_slots.availability.'.$this->availabilityStatus($slot));
    }

    public function remainingCapacity(AppointmentSlot $slot): int
    {
        return max(0, (int) $slot->capacity - (int) $slot->booked_count);
    }

    public function utilizationRate(AppointmentSlot $slot): ?float
    {
        $capacity = (int) $slot->capacity;

        if ($capacity <= 0) {
            return null;
        }

        return round(((int) $slot->booked_count / $capacity) * 100, 2);
    }

    public function durationMinutes(AppointmentSlot $slot): int
    {
        $start = SlotIdentity::normalizeTime((string) $slot->start_time);
        $end = SlotIdentity::normalizeTime((string) $slot->end_time);

        $startAt = Carbon::createFromFormat('H:i:s', $start);
        $endAt = Carbon::createFromFormat('H:i:s', $end);

        return (int) $startAt->diffInMinutes($endAt);
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    public function testTypePayload(AppointmentSlot $slot): ?array
    {
        if ($slot->testType === null) {
            return null;
        }

        return [
            'id' => $slot->testType->id,
            'code' => $slot->testType->code,
            'name' => EmployeeMessageTranslator::get('employee.test_types.'.$slot->testType->code),
        ];
    }

    /**
     * @return array{id: int, name: string, address: ?string}|null
     */
    public function centerPayload(AppointmentSlot $slot): ?array
    {
        if ($slot->appointmentCenter === null) {
            return null;
        }

        return [
            'id' => $slot->appointmentCenter->id,
            'name' => $slot->appointmentCenter->name,
            'address' => $slot->appointmentCenter->address,
        ];
    }
}
