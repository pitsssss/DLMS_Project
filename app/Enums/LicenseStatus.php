<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Blocked = 'blocked';
    case Renewed = 'renewed';
    case Inactive = 'inactive';
}
