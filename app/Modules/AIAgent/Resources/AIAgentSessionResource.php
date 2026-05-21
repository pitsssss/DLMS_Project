<?php

namespace App\Modules\AIAgent\Resources;

use App\Modules\AIAgent\Models\AIAgentSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AIAgentSession */
class AIAgentSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'current_intent' => $this->current_intent,
            'context' => $this->context ?? [],
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'messages' => AIAgentMessageResource::collection($this->whenLoaded('messages')),
            'actions' => AIAgentActionResource::collection($this->whenLoaded('actions')),
        ];
    }
}
