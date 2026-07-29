<?php

namespace App\Modules\Dashboard\Resources;

use App\Enums\AppointmentStatus;
use App\Models\TestAppointment;
use App\Models\User;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestAppointment */
class DashboardAppointmentSlotBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TestAppointment $appointment */
        $appointment = $this->resource;
        $actor = $request->user();
        $status = $appointment->status instanceof AppointmentStatus
            ? $appointment->status->value
            : (string) $appointment->status;

        $canViewApplication = $actor instanceof User
            && ($actor->hasPermission('view_applications') || $actor->hasPermission('manage_applications'));

        $payload = [
            'id' => $appointment->id,
            'application' => $appointment->application ? [
                'id' => $appointment->application->id,
                'application_number' => $appointment->application->application_number,
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
            'status' => $status,
            'status_label' => Msg::get('appointment_slots.booking_statuses.'.$status),
            'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            'created_at' => $appointment->created_at?->toIso8601String(),
            'actions' => [
                'can_view_application' => $canViewApplication && $appointment->application_id !== null,
            ],
        ];

        if ($status === AppointmentStatus::Cancelled->value) {
            $payload['cancellation'] = [
                'cancelled_at' => $appointment->cancelled_at?->toIso8601String(),
                'reason' => $appointment->cancellation_reason,
            ];
        }

        if ($appointment->testResult !== null) {
            $result = $appointment->testResult->result?->value ?? (string) $appointment->testResult->result;
            $payload['test_result'] = [
                'result' => $result,
                'result_label' => Msg::get('tests.statuses.'.$result),
            ];
        }

        return $payload;
    }
}
