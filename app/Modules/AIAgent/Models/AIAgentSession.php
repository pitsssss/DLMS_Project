<?php

namespace App\Modules\AIAgent\Models;

use App\Models\User;
use App\Modules\AIAgent\Enums\AgentSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIAgentSession extends Model
{
    protected $table = 'ai_agent_sessions';

    protected $fillable = [
        'user_id',
        'status',
        'current_intent',
        'context',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_message_at' => 'datetime',
            'status' => AgentSessionStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AIAgentMessage::class, 'session_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AIAgentAction::class, 'session_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(AIAgentEvaluation::class, 'session_id');
    }
}
