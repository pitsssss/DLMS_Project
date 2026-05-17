<?php

namespace App\Modules\Licenses\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\License */
class LicenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'license_number' => $this->license_number,
            'status' => $this->status->value,
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'license_type' => $this->whenLoaded('licenseType', fn () => [
                'id' => $this->licenseType->id,
                'name' => $this->licenseType->name,
                'code' => $this->licenseType->code,
            ]),
            'application' => $this->whenLoaded('application', fn () => [
                'id' => $this->application->id,
                'application_number' => $this->application->application_number,
                'status' => $this->application->status->value,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
