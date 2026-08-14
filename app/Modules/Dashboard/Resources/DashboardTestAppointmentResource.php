<?php

namespace App\Modules\Dashboard\Resources;

use App\Enums\AppointmentStatus;
use App\Models\TestAppointment;
use App\Models\User;
use App\Modules\Appointments\Support\AppointmentSlotPresenter;
use App\Modules\Appointments\Support\SlotIdentity;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Dashboard\Support\DashboardTestAppointmentActions;
use App\Modules\Tests\Services\TestResultService;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestAppointment */
class DashboardTestAppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TestAppointment $appointment */
        $appointment = $this->resource;
        $actor = $request->user();
        $status = $appointment->status instanceof AppointmentStatus
            ? $appointment->status->value
            : (string) $appointment->status;

        $slot = $appointment->appointmentSlot;

        return [
            'id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            'status' => $status,
            'status_label' => Msg::get('appointment_slots.booking_statuses.'.$status),
            'application' => $appointment->application ? [
                'id' => $appointment->application->id,
                'application_number' => $appointment->application->application_number,
                'status' => DashboardPaymentPresenter::applicationStatus($appointment->application->status),
            ] : null,
            'citizen' => $appointment->citizen ? [
                'id' => $appointment->citizen->id,
                'name' => $appointment->citizen->name,
            ] : null,
            'test_type' => $appointment->testType ? [
                'id' => $appointment->testType->id,
                'code' => $appointment->testType->code,
                'name' => EmployeeMessageTranslator::get('employee.test_types.'.$appointment->testType->code),
            ] : null,
            'previous_attempts_count' => (int) ($appointment->previous_attempts_count ?? 0),
            'next_attempt_number' => (int) ($appointment->next_attempt_number ?? 1),
            'slot' => $slot ? [
                'id' => $slot->id,
                'date' => $slot->date?->format('Y-m-d'),
                'start_time' => SlotIdentity::normalizeTime((string) $slot->start_time),
                'end_time' => SlotIdentity::normalizeTime((string) $slot->end_time),
                'location' => $slot->location,
                'appointment_center' => app(AppointmentSlotPresenter::class)->centerPayload($slot),
            ] : null,
            'actions' => $actor instanceof User
                ? DashboardTestAppointmentActions::for(
                    $appointment,
                    $actor,
                    app(TestResultService::class)
                )
                : [
                    'can_record_result' => false,
                    'can_view_application' => false,
                ],
        ];
    }
}
