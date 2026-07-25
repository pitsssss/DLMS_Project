<?php

namespace App\Modules\Dashboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardCitizenResource extends JsonResource
{
  
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'national_id' => $this->national_id,
            'is_active' => (bool) $this->is_active,
            'profile_status' => $this->profile_status?->value,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'governorate' => $this->governorate,
            'address' => $this->address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
