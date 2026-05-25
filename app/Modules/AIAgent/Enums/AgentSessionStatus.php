<?php

namespace App\Modules\AIAgent\Enums;

enum AgentSessionStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
