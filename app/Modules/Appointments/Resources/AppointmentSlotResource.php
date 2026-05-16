<?php

namespace App\Modules\Appointments\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AppointmentSlot */
class AppointmentSlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $remaining = max(0, (int) $this->capacity - (int) $this->booked_count);

        return [
            'id' => $this->id,
            'test_type_id' => $this->test_type_id,
            'test_type' => $this->whenLoaded('testType', fn () => [
                'id' => $this->testType->id,
                'name' => $this->testType->name,
                'code' => $this->testType->code,
            ]),
            'date' => $this->date?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'capacity' => $this->capacity,
            'booked_count' => $this->booked_count,
            'remaining_capacity' => $remaining,
            'location' => $this->location,
            'is_active' => $this->is_active,
        ];
    }
}
