<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'national_id' => $this->national_id,
            'user_type' => $this->user_type?->value,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'governorate' => $this->governorate,
            'address' => $this->address,
            'language' => $this->language ?? config('localization.default', 'ar'),
            'profile_completed' => (bool) $this->profile_completed,
            'profile_status' => $this->profile_status instanceof \App\Enums\ProfileStatus
                ? $this->profile_status->value
                : (string) ($this->profile_status ?? 'incomplete'),
            'profile_rejection_reason' => $this->profile_rejection_reason,
            'profile_submitted_at' => $this->profile_submitted_at?->toIso8601String(),
            'profile_reviewed_at' => $this->profile_reviewed_at?->toIso8601String(),
            'is_active' => (bool) $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'role' => $this->whenLoaded('role', function () {
                return [
                    'id' => $this->role->id,
                    'name' => $this->role->name,
                ];
            }),
        ];
    }
}
