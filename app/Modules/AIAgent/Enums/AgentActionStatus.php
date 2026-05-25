<?php

namespace App\Modules\AIAgent\Enums;

enum AgentActionStatus: string
{
    case Pending = 'pending';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Confirmed = 'confirmed';
    case Executed = 'executed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
