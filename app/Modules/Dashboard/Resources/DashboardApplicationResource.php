<?php

namespace App\Modules\Dashboard\Resources;

use App\Enums\ApplicationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'status' => $this->statusValue(),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),

            'citizen' => $this->relationLoaded('citizen') && $this->citizen ? [
                'name' => $this->citizen->name,
                'phone' => $this->citizen->phone ?? null,
            ] : null,

            'license_type' => $this->relationLoaded('licenseType') && $this->licenseType ? [
                'name' => $this->licenseType->name,
            ] : null,

            'service_type' => $this->relationLoaded('serviceType') && $this->serviceType ? [
                'name' => $this->serviceType->name,
            ] : null,
        ];
    }

    private function statusValue(): ?string
    {
        if ($this->status instanceof ApplicationStatus) {
            return $this->status->value;
        }

        return $this->status;
    }
}
