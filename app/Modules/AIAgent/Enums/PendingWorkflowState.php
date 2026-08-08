<?php

namespace App\Modules\AIAgent\Enums;

enum PendingWorkflowState: string
{
    case AwaitingApplicationChoice = 'awaiting_application_choice';
    case AwaitingAppointmentChoice = 'awaiting_appointment_choice';
    case AwaitingAppointmentSlotChoice = 'awaiting_appointment_slot_choice';
    case Resuming = 'resuming';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';
}
