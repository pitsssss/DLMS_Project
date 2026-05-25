<?php

namespace App\Modules\Appointments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Requests\CancelAppointmentRequest;
use App\Modules\Appointments\Requests\RescheduleAppointmentRequest;
use App\Modules\Appointments\Resources\TestAppointmentResource;
use App\Modules\Appointments\Services\AppointmentService;

class AppointmentController extends Controller
{
    public function reschedule(
        RescheduleAppointmentRequest $request,
        int $appointment,
        AppointmentService $appointments
    ) {
        $model = $appointments->reschedule(
            $request->user(),
            $appointment,
            (int) $request->validated('appointment_slot_id')
        );

        return $this->successResponse(
            new TestAppointmentResource($model),
            'messages.appointments.rescheduled'
        );
    }

    public function cancel(
        CancelAppointmentRequest $request,
        int $appointment,
        AppointmentService $appointments
    ) {
        $model = $appointments->cancel(
            $request->user(),
            $appointment,
            $request->validated('cancellation_reason')
        );

        return $this->successResponse(
            new TestAppointmentResource($model),
            'messages.appointments.cancelled'
        );
    }
}
