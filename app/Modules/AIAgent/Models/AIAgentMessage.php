<?php

namespace App\Modules\AIAgent\Models;

use App\Modules\AIAgent\Enums\AgentMessageRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIAgentMessage extends Model
{
    protected $table = 'ai_agent_messages';

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'role' => AgentMessageRole::class,
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AIAgentSession::class, 'session_id');
    }
}
