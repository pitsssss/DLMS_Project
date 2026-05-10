<?php

namespace App\Enums;

enum ServiceCode: string
{
    case NewLicense = 'new_license';
    case RenewLicense = 'renew_license';
    case LostReplacement = 'lost_replacement';
    case DamagedReplacement = 'damaged_replacement';
    case LicenseUnblock = 'license_unblock';
}
