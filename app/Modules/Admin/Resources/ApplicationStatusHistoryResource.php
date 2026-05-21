<?php

namespace App\Modules\Admin\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApplicationStatusHistory */
class ApplicationStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'old_status' => $this->old_status?->value,
            'new_status' => $this->new_status->value,
            'changed_by' => $this->changed_by,
            'changed_by_user' => $this->whenLoaded('changedByUser', fn () => $this->changedByUser ? [
                'id' => $this->changedByUser->id,
                'name' => $this->changedByUser->name,
            ] : null),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
