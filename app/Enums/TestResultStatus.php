<?php

namespace App\Enums;

enum TestResultStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NoShow = 'no_show';
    case Pending = 'pending';
}
