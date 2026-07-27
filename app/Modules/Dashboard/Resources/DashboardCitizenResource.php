<?php

namespace App\Modules\Dashboard\Resources;

use App\Support\EmployeeMessageTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class DashboardCitizenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'national_id'    => $this->national_id,
            'phone'          => $this->phone,
            'email'          => $this->email,
            'is_active'      => (bool) $this->is_active,
            'account_status' => [
                'value' => $this->is_active ? 'active' : 'inactive',
                'label' => $this->is_active ? 'فعال' : 'غير فعال',
            ],
            'profile_status' => $this->profile_status?->value,
            'profile_status_info' => $this->profile_status ? [
                'value' => $this->profile_status->value,
                'label' => EmployeeMessageTranslator::get('employee.profile_statuses.' . $this->profile_status->value),
            ] : null,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
