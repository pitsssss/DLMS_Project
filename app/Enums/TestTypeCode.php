<?php

namespace App\Enums;

enum TestTypeCode: string
{
    case Vision = 'vision';
    case Theory = 'theory';
    case Practical = 'practical';
    case Specialized = 'specialized';
}
