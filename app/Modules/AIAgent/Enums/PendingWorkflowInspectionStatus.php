<?php

namespace App\Modules\AIAgent\Enums;

enum PendingWorkflowInspectionStatus: string
{
    case None = 'none';
    case Active = 'active';
    case Expired = 'expired';
    case InvalidState = 'invalid_state';
}
