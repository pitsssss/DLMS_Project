<?php

namespace App\Modules\AIAgent\Enums;

enum AgentMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
}
