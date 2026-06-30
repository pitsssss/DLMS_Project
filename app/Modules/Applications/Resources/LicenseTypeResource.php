<?php

namespace App\Modules\Applications\Resources;

use App\Support\EmployeeMessageTranslator;
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
            'id'             => $this->id,
            'name'           => EmployeeMessageTranslator::get('employee.license_types.' . $this->code),
            'code'           => $this->code,
            'minimum_age'    => $this->minimum_age,
            'validity_years' => $this->validity_years,
        ];
    }
}
