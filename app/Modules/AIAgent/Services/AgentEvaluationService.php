<?php

namespace App\Modules\AIAgent\Services;

use App\Modules\AIAgent\Models\AIAgentEvaluation;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;

class AgentEvaluationService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        AIAgentSession $session,
        ?AIAgentMessage $assistantMessage,
        array $payload,
        int $latencyMs,
        bool $wasFallback,
        ?string $toolSelected = null,
    ): AIAgentEvaluation {
        $safetyScore = ($payload['safety_status'] ?? 'safe') === 'blocked' ? 0.0 : 1.0;

        return AIAgentEvaluation::query()->create([
            'session_id' => $session->id,
            'message_id' => $assistantMessage?->id,
            'intent' => $payload['intent'] ?? null,
            'confidence' => $payload['confidence'] ?? null,
            'safety_score' => $safetyScore,
            'tool_selected' => $toolSelected,
            'requires_human_support' => (bool) ($payload['requires_human_support'] ?? false),
            'latency_ms' => $latencyMs,
            'model_used' => $wasFallback ? 'fallback' : config('ai.gemini.model'),
            'was_fallback' => $wasFallback,
            'metadata' => [
                'missing_slots' => $payload['missing_slots'] ?? [],
                'requires_confirmation' => $payload['requires_confirmation'] ?? false,
                'safety_status' => $payload['safety_status'] ?? 'safe',
            ],
        ]);
    }
}
