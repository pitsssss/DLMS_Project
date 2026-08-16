<?php

namespace App\Services\Mail;

use RuntimeException;
use Throwable;

/**
 * OTP email delivery failure. Messages must never contain the API key, OTP, or request bodies.
 */
class BrevoDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly BrevoErrorCategory $category,
        public readonly int $httpStatus = 0,
        public readonly ?string $messageId = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
