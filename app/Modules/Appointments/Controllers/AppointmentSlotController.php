<?php

namespace App\Modules\Appointments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Resources\AppointmentSlotResource;
use App\Modules\Appointments\Services\AppointmentService;
use Illuminate\Http\Request;

class AppointmentSlotController extends Controller
{
    public function index(Request $request, AppointmentService $appointments)
    {
        $validated = $request->validate([
            'test_type_id' => ['required', 'integer', 'exists:test_types,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $slots = $appointments->listAvailableSlots(
            (int) $validated['test_type_id'],
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null
        );

        return $this->successResponse(
            AppointmentSlotResource::collection($slots)->resolve(),
            'messages.appointments.slots'
        );
    }
}
