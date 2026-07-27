<?php

namespace App\Modules\Dashboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class DashboardCitizenAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'action' => $this->action,
            'actor'  => $this->whenLoaded('user', fn () => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'entity' => [
                'type' => $this->entity_type,
                'id'   => $this->entity_id,
            ],
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
