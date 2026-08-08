<?php

namespace App\Modules\Settings\Resources;

use App\Enums\ProfileStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'national_id' => $this->national_id,
                'profile_status' => $this->profile_status instanceof ProfileStatus
                    ? $this->profile_status->value
                    : (string) ($this->profile_status ?? 'incomplete'),
                'profile_completed' => (bool) $this->profile_completed,
            ],
            'preferences' => [
                'language' => $this->language ?? config('localization.default', config('content.defaults.language', 'ar')),
                'theme' => $this->theme ?? config('content.defaults.theme', 'system'),
            ],
            'available_languages' => config('content.languages', []),
            'available_themes' => config('content.themes', []),
        ];
    }
}
