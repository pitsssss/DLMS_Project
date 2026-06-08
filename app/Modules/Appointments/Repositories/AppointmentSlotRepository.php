<?php

namespace App\Modules\Appointments\Repositories;

use App\Models\AppointmentSlot;
use Illuminate\Database\Eloquent\Collection;

class AppointmentSlotRepository
{
    /**
     * @return Collection<int, AppointmentSlot>
     */
    public function listAvailable(int $testTypeId, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $query = AppointmentSlot::query()
            ->where('test_type_id', $testTypeId)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->with(['testType', 'appointmentCenter'])
            ->orderBy('date')
            ->orderBy('start_time');

        if ($fromDate !== null && $fromDate !== '') {
            $query->where('date', '>=', $fromDate);
        }

        if ($toDate !== null && $toDate !== '') {
            $query->where('date', '<=', $toDate);
        }

        return $query->get();
    }

    public function findAvailableForBooking(int $slotId, int $testTypeId): ?AppointmentSlot
    {
        return AppointmentSlot::query()
            ->whereKey($slotId)
            ->where('test_type_id', $testTypeId)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->lockForUpdate()
            ->first();
    }
}
