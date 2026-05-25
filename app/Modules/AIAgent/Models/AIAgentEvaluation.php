<?php

namespace App\Modules\AIAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIAgentEvaluation extends Model
{
    protected $table = 'ai_agent_evaluations';

    protected $fillable = [
        'session_id',
        'message_id',
        'intent',
        'confidence',
        'safety_score',
        'tool_selected',
        'requires_human_support',
        'latency_ms',
        'model_used',
        'was_fallback',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'safety_score' => 'decimal:4',
            'requires_human_support' => 'boolean',
            'was_fallback' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AIAgentSession::class, 'session_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AIAgentMessage::class, 'message_id');
    }
}
