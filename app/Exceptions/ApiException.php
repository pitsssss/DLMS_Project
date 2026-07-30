<?php

namespace App\Exceptions;

use App\Support\CitizenMessageTranslator;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        string $message,
        protected int $statusCode = 400,
        public array $errors = [],
        protected array $replace = [],
        protected ?string $errorCode = null,
        public ?array $data = null,
    ) {
        parent::__construct(self::resolveMessage($message, $replace));
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function resolveMessage(string $message, array $replace = []): string
    {
        if (str_starts_with($message, 'messages.')) {
            return CitizenMessageTranslator::get($message, $replace);
        }

        return $message;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $payload = [
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => empty($this->errors) ? (object) [] : $this->errors,
        ];

        if ($this->errorCode !== null && $this->errorCode !== '') {
            $payload['code'] = $this->errorCode;
        }

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        }

        return response()->json($payload, $this->statusCode);
    }
}
