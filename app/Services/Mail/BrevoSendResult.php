<?php

namespace App\Services\Mail;

final class BrevoSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $httpStatus,
        public readonly ?string $messageId = null,
        public readonly ?BrevoErrorCategory $category = null,
        public readonly bool $retryable = false,
        public readonly ?string $transportReason = null,
    ) {}

    public static function success(int $httpStatus, ?string $messageId): self
    {
        return new self(
            success: true,
            httpStatus: $httpStatus,
            messageId: $messageId,
        );
    }

    public static function failure(
        int $httpStatus,
        BrevoErrorCategory $category,
        ?string $transportReason = null,
    ): self {
        return new self(
            success: false,
            httpStatus: $httpStatus,
            category: $category,
            retryable: $category->isRetryable(),
            transportReason: $transportReason,
        );
    }
}
