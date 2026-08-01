<?php

namespace App\Enums;

enum EmployeeSessionEndedReason: string
{
    case ExplicitLogout = 'explicit_logout';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case CredentialMissing = 'credential_missing';
}
