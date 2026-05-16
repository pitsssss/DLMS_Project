<?php

namespace App\Modules\Appointments\Repositories;

use App\Models\TestAppointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class AppointmentRepository
{
    /**
     * @return Collection<int, TestAppointment>
     */
    public function listForApplication(int $applicationId, int $citizenId): Collection
    {
        return TestAppointment::query()
            ->where('application_id', $applicationId)
            ->where('citizen_id', $citizenId)
            ->with(['appointmentSlot', 'testType', 'testResult'])
            ->orderByDesc('id')
            ->get();
    }

    public function findOwnedByCitizen(User $citizen, int $appointmentId): ?TestAppointment
    {
        return TestAppointment::query()
            ->whereKey($appointmentId)
            ->where('citizen_id', $citizen->id)
            ->with(['appointmentSlot', 'testType', 'application'])
            ->first();
    }

    public function findForEmployee(int $appointmentId): ?TestAppointment
    {
        return TestAppointment::query()
            ->whereKey($appointmentId)
            ->with(['appointmentSlot', 'testType', 'application', 'citizen'])
            ->first();
    }
}
