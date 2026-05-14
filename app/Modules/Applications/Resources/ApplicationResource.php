<?php

namespace App\Modules\Applications\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LicenseApplication */
class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'status' => $this->status->value,
            'license_type' => $this->whenLoaded('licenseType', function () {
                return [
                    'id' => $this->licenseType->id,
                    'name' => $this->licenseType->name,
                    'code' => $this->licenseType->code,
                ];
            }),
            'service_type' => $this->whenLoaded('serviceType', function () {
                return [
                    'id' => $this->serviceType->id,
                    'name' => $this->serviceType->name,
                    'code' => $this->serviceType->code,
                ];
            }),
            'current_test_type' => $this->whenLoaded('currentTestType', function () {
                if ($this->currentTestType === null) {
                    return null;
                }

                return [
                    'id' => $this->currentTestType->id,
                    'name' => $this->currentTestType->name,
                    'code' => $this->currentTestType->code,
                ];
            }),
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'documents' => $this->whenLoaded('applicationDocuments', function () {
                /** @var Collection<int, \App\Models\ApplicationDocument> $docs */
                $docs = $this->applicationDocuments;

                return $docs
                    ->map(fn ($doc) => (new ApplicationDocumentResource($doc))->resolve())
                    ->values()
                    ->all();
            }),
        ];
    }
}
