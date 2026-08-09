<?php

namespace App\Modules\Firebase\Support;

final class FcmSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $httpStatus,
        public readonly ?string $messageName = null,
        public readonly ?FcmErrorCategory $errorCategory = null,
        public readonly ?string $providerErrorCode = null,
        public readonly ?string $providerStatus = null,
        public readonly bool $retryable = false,
        public readonly bool $invalidToken = false,
        public readonly bool $validateOnly = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {}

    public static function success(int $httpStatus, ?string $messageName, bool $validateOnly = false): self
    {
        return new self(
            success: true,
            httpStatus: $httpStatus,
            messageName: $messageName,
            validateOnly: $validateOnly,
        );
    }

    public static function failure(
        int $httpStatus,
        FcmErrorCategory $category,
        ?string $providerErrorCode = null,
        ?string $providerStatus = null,
        bool $validateOnly = false,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self(
            success: false,
            httpStatus: $httpStatus,
            errorCategory: $category,
            providerErrorCode: $providerErrorCode,
            providerStatus: $providerStatus,
            retryable: $category->isRetryable(),
            invalidToken: $category->indicatesInvalidToken(),
            validateOnly: $validateOnly,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'http_status' => $this->httpStatus,
            'message_name' => $this->messageName,
            'error_category' => $this->errorCategory?->value,
            'provider_error_code' => $this->providerErrorCode,
            'provider_status' => $this->providerStatus,
            'retryable' => $this->retryable,
            'invalid_token' => $this->invalidToken,
            'validate_only' => $this->validateOnly,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }
}
