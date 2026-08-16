<?php

namespace App\Services\Mail;

enum BrevoErrorCategory: string
{
    case Configuration = 'configuration';
    case Authentication = 'authentication';
    case Validation = 'validation';
    case RateLimit = 'rate_limit';
    case Server = 'server';
    case Timeout = 'timeout';
    case Connection = 'connection';
    case Ssl = 'ssl';
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimit, self::Server, self::Timeout, self::Connection, self::Unknown => true,
            default => false,
        };
    }
}
