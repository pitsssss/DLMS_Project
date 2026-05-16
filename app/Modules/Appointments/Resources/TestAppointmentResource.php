<?php

namespace App\Modules\Appointments\Resources;

use App\Modules\Tests\Resources\TestResultResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TestAppointment */
class TestAppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'test_type_id' => $this->test_type_id,
            'test_type' => $this->whenLoaded('testType', fn () => [
                'id' => $this->testType->id,
                'name' => $this->testType->name,
                'code' => $this->testType->code,
            ]),
            'status' => $this->status->value,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'appointment_slot' => $this->whenLoaded('appointmentSlot', fn () => (new AppointmentSlotResource($this->appointmentSlot))->resolve()),
            'test_result' => $this->whenLoaded('testResult', fn () => $this->testResult
                ? (new TestResultResource($this->testResult))->resolve()
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
