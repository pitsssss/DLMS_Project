<?php

namespace App\Enums;

enum ProfileStatus: string
{
    case Incomplete = 'incomplete';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
