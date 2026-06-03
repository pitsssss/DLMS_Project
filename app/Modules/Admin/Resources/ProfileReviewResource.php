<?php

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->profile_status;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'national_id' => $this->national_id,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'governorate' => $this->governorate,
            'address' => $this->address,
            'profile_completed' => (bool) $this->profile_completed,
            'profile_status' => $status instanceof \App\Enums\ProfileStatus ? $status->value : (string) $status,
            'profile_submitted_at' => $this->profile_submitted_at?->toIso8601String(),
            'profile_reviewed_at' => $this->profile_reviewed_at?->toIso8601String(),
            'profile_rejection_reason' => $this->profile_rejection_reason,
        ];
    }
}
