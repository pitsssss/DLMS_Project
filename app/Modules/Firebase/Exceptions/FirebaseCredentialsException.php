<?php

namespace App\Modules\Firebase\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Safe Firebase configuration/credential failures.
 * Messages must never contain Base64 credentials, JSON, or private keys.
 */
class FirebaseCredentialsException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
