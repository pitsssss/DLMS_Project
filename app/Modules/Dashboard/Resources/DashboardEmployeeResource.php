<?php

namespace App\Modules\Dashboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardEmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'user_type' => $this->user_type?->value,
            'is_active' => (bool) $this->is_active,
            'role' => $this->whenLoaded('role', fn () => [
                'name' => $this->role->name,
                'display_name' => $this->role->display_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
