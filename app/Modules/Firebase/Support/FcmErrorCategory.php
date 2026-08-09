<?php

namespace App\Modules\Firebase\Support;

enum FcmErrorCategory: string
{
    case Authentication = 'AUTHENTICATION';
    case InvalidArgument = 'INVALID_ARGUMENT';
    case Unregistered = 'UNREGISTERED';
    case Quota = 'QUOTA';
    case Server = 'SERVER';
    case Unknown = 'UNKNOWN';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Server, self::Quota => true,
            default => false,
        };
    }

    public function indicatesInvalidToken(): bool
    {
        return $this === self::Unregistered;
    }
}
