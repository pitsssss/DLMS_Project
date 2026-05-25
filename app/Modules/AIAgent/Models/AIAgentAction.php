<?php

namespace App\Modules\AIAgent\Models;

use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIAgentAction extends Model
{
    protected $table = 'ai_agent_actions';

    protected $fillable = [
        'session_id',
        'user_id',
        'action_name',
        'arguments',
        'status',
        'requires_confirmation',
        'confirmation_message',
        'result',
        'error_message',
        'confirmed_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'requires_confirmation' => 'boolean',
            'status' => AgentActionStatus::class,
            'confirmed_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AIAgentSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
