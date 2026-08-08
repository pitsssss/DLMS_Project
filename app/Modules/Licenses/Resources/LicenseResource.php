<?php

namespace App\Modules\Licenses\Resources;

use App\Modules\Licenses\Support\LicenseEffectiveStatus;
use App\Support\CitizenCatalogLabel;
use App\Support\CitizenMessageTranslator;
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
        $effective = LicenseEffectiveStatus::resolve($this->resource);

        return [
            'id' => $this->id,
            'license_number' => $this->license_number,
            'status' => $effective->value,
            'status_label' => CitizenMessageTranslator::get('messages.licenses.statuses.'.$effective->value),
            'stored_status' => $this->status->value,
            'effective_status' => $effective->value,
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'days_remaining' => LicenseEffectiveStatus::daysRemaining($this->resource),
            'is_expiring_soon' => LicenseEffectiveStatus::isExpiringSoon($this->resource),
            'license_type' => $this->whenLoaded('licenseType', fn () => [
                'id' => $this->licenseType->id,
                'name' => CitizenCatalogLabel::licenseType(
                    (string) $this->licenseType->code,
                    $this->licenseType->name
                ),
                'code' => $this->licenseType->code,
            ]),
            'application' => $this->whenLoaded('application', fn () => [
                'id' => $this->application->id,
                'application_number' => $this->application->application_number,
                'status' => $this->application->status->value,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'can_renew' => $this->when(isset($this->can_renew), (bool) $this->can_renew),
            'can_request_lost_replacement' => $this->when(isset($this->can_request_lost_replacement), (bool) $this->can_request_lost_replacement),
            'can_request_damaged_replacement' => $this->when(isset($this->can_request_damaged_replacement), (bool) $this->can_request_damaged_replacement),
        ];
    }
}
