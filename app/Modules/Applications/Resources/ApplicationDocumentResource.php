<?php

namespace App\Modules\Applications\Resources;

use App\Enums\DocumentRejectionReason;
use App\Enums\DocumentStatus;
use App\Support\ArabicMessageTranslator;
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
            'rejection' => $this->rejectionPayload(),
            // Legacy compatibility display text — prefer rejection.* for new clients
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

    /**
     * @return array{code: ?string, label: ?string, details: ?string}|null
     */
    private function rejectionPayload(): ?array
    {
        if ($this->status !== DocumentStatus::Rejected) {
            return null;
        }

        $code = DocumentRejectionReason::tryFrom((string) $this->rejection_reason_code);

        return [
            'code' => $code?->value ?? $this->rejection_reason_code,
            'label' => $code?->label() ?? ArabicMessageTranslator::resolveStoredLabel($this->rejection_reason),
            'details' => $this->rejection_details,
        ];
    }
}
