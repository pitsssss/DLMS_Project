<?php

namespace App\Modules\Tests\Resources;

use App\Support\CitizenCatalogLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TestResult */
class TestResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'test_appointment_id' => $this->test_appointment_id,
            'test_type_id' => $this->test_type_id,
            'test_type' => $this->whenLoaded('testType', fn () => [
                'id' => $this->testType->id,
                'name' => CitizenCatalogLabel::testType((string) $this->testType->code, $this->testType->name),
                'code' => $this->testType->code,
            ]),
            'result' => $this->result->value,
            'attempt_number' => $this->attempt_number,
            'notes' => $this->notes,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ]),
        ];
    }
}
