<?php

namespace App\Modules\AIAgent\Resources;

use App\Modules\AIAgent\Models\AIAgentAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AIAgentAction */
class AIAgentActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->action_name,
            'arguments' => $this->arguments ?? [],
            'status' => $this->status->value,
            'requires_confirmation' => $this->requires_confirmation,
            'confirmation_message' => $this->confirmation_message,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
