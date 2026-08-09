<?php

namespace App\Modules\Firebase\Services;

use App\Modules\Firebase\Exceptions\FirebaseCredentialsException;
use App\Modules\Firebase\Support\FcmErrorCategory;
use App\Modules\Firebase\Support\FcmErrorClassifier;
use App\Modules\Firebase\Support\FcmSendResult;
use App\Modules\Firebase\Support\FirebaseTls;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reusable FCM HTTP v1 transport. Does not register devices or enqueue jobs.
 */
class FcmClient
{
    public function __construct(
        private readonly FirebaseAccessTokenProvider $tokens,
        private readonly FirebaseCredentialsProvider $credentials,
        private readonly FcmErrorClassifier $errors = new FcmErrorClassifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Flattened to string values for FCM data messages.
     */
    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = [],
        bool $validateOnly = false,
    ): FcmSendResult {
        $projectId = $this->credentials->configuredProjectId();
        $baseUri = rtrim((string) config('firebase.fcm.base_uri', 'https://fcm.googleapis.com'), '/');
        $url = "{$baseUri}/v1/projects/{$projectId}/messages:send";

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
            ],
        ];

        $stringData = $this->stringifyData($data);
        if ($stringData !== []) {
            $payload['message']['data'] = $stringData;
        }

        if ($validateOnly) {
            $payload['validate_only'] = true;
        }

        try {
            $accessToken = $this->tokens->getAccessToken();
        } catch (FirebaseCredentialsException $e) {
            Log::warning('FCM send aborted: OAuth token unavailable.', [
                'category' => FcmErrorCategory::Authentication->value,
            ]);

            return FcmSendResult::failure(
                0,
                FcmErrorCategory::Authentication,
                validateOnly: $validateOnly,
            );
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->withOptions([
                    'verify' => FirebaseTls::verify(),
                    'connect_timeout' => (float) config('firebase.fcm.connect_timeout_seconds', 5),
                    'timeout' => (float) config('firebase.fcm.timeout_seconds', 15),
                ])
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('FCM HTTP connection failed.', [
                'category' => FcmErrorCategory::Server->value,
            ]);

            return FcmSendResult::failure(
                0,
                FcmErrorCategory::Server,
                validateOnly: $validateOnly,
            );
        } catch (Throwable $e) {
            Log::warning('FCM HTTP request failed unexpectedly.', [
                'category' => FcmErrorCategory::Unknown->value,
            ]);

            return FcmSendResult::failure(
                0,
                FcmErrorCategory::Unknown,
                validateOnly: $validateOnly,
            );
        }

        return $this->parseResponse($response, $validateOnly);
    }

    private function parseResponse(Response $response, bool $validateOnly): FcmSendResult
    {
        $status = $response->status();
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->successful()) {
            $name = data_get($body, 'name');

            return FcmSendResult::success(
                $status,
                is_string($name) ? $name : null,
                $validateOnly,
            );
        }

        $category = $this->errors->classify($status, $body);

        Log::warning('FCM HTTP v1 send rejected.', [
            'http_status' => $status,
            'category' => $category->value,
            'provider_status' => $this->errors->providerStatus($body),
            'provider_error_code' => $this->errors->providerErrorCode($body),
            'validate_only' => $validateOnly,
        ]);

        return FcmSendResult::failure(
            $status,
            $category,
            $this->errors->providerErrorCode($body),
            $this->errors->providerStatus($body),
            $validateOnly,
            $this->parseRetryAfterSeconds($response),
        );
    }

    /**
     * Parse HTTP Retry-After (delay-seconds or HTTP-date).
     * Malformed values return null so callers fall back to configured backoff.
     * Never logs or exposes the raw header publicly.
     */
    public function parseRetryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');
        if ($header === null || $header === '') {
            return null;
        }

        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return max(0, (int) $header);
        }

        // Reject obvious garbage before strtotime (malformed → backoff fallback).
        if (! preg_match('/^[A-Za-z0-9 ,:\-\+]+$/', $header)) {
            return null;
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }
            $out[(string) $key] = match (true) {
                is_string($value) => $value,
                is_bool($value) => $value ? '1' : '0',
                is_int($value), is_float($value) => (string) $value,
                $value === null => '',
                default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?: '',
            };
        }

        return $out;
    }
}
