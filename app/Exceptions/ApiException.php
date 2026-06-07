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
     */
    public function __construct(
        string $message,
        protected int $statusCode = 400,
        public array $errors = [],
        protected array $replace = [],
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

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => empty($this->errors) ? (object) [] : $this->errors,
        ], $this->statusCode);
    }
}
