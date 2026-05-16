<?php

namespace App\Modules\Appointments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Requests\BookApplicationAppointmentRequest;
use App\Modules\Appointments\Resources\TestAppointmentResource;
use App\Modules\Appointments\Services\AppointmentService;
use Illuminate\Http\Request;

class ApplicationAppointmentController extends Controller
{
    public function availableTests(Request $request, int $application, AppointmentService $appointments)
    {
        $items = $appointments->availableTestsForApplication($request->user(), $application);

        return $this->successResponse([
            'application_id' => $application,
            'tests' => $items,
        ], 'Available tests retrieved successfully.');
    }

    public function index(Request $request, int $application, AppointmentService $appointments)
    {
        $list = $appointments->listApplicationAppointments($request->user(), $application);

        return $this->successResponse(
            TestAppointmentResource::collection($list)->resolve(),
            'Application appointments retrieved successfully.'
        );
    }

    public function store(
        BookApplicationAppointmentRequest $request,
        int $application,
        AppointmentService $appointments
    ) {
        $appointment = $appointments->book(
            $request->user(),
            $application,
            (int) $request->validated('appointment_slot_id')
        );

        return $this->successResponse(
            new TestAppointmentResource($appointment),
            'Test appointment booked successfully.'
        );
    }
}
