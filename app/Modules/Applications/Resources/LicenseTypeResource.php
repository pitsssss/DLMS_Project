<?php

namespace App\Modules\Applications\Resources;

use App\Support\CitizenCatalogLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LicenseType */
class LicenseTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => CitizenCatalogLabel::licenseType((string) $this->code, $this->name),
            'code' => $this->code,
            'minimum_age' => $this->minimum_age,
            'validity_years' => $this->validity_years,
        ];
    }
}
