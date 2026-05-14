<?php

namespace App\Modules\Applications\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApplicationDocument */
class ApplicationDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'required_document' => $this->whenLoaded('requiredDocument', function () {
                return [
                    'id' => $this->requiredDocument->id,
                    'name' => $this->requiredDocument->name,
                    'code' => $this->requiredDocument->code,
                ];
            }),
            'application' => $this->whenLoaded('application', function () {
                return [
                    'id' => $this->application->id,
                    'application_number' => $this->application->application_number,
                    'status' => $this->application->status->value,
                ];
            }),
        ];
    }
}
