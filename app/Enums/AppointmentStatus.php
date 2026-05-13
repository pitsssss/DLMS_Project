<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Booked = 'booked';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
}
