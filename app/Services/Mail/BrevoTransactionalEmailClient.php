<?php

namespace App\Services\Mail;

use App\Support\HttpTls;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one transactional email through Brevo's HTTPS API (not SMTP).
 *
 * Official POST /v3/smtp/email does not document a single-send idempotency key.
 * The documented idempotencyKey applies to batch messageVersions. This client
 * therefore does not send invented idempotency headers. Queue retries after an
 * ambiguous timeout may duplicate a still-valid OTP email.
 */
class BrevoTransactionalEmailClient
{
    public const SEND_URL = 'https://api.brevo.com/v3/smtp/email';

    public function send(string $to, string $subject, string $html, ?string $text = null): BrevoSendResult
    {
        $apiKey = trim((string) config('services.brevo.api_key'));
        $senderEmail = trim((string) config('services.brevo.sender_email'));
        $senderName = trim((string) config('services.brevo.sender_name', 'SYRTAK'));

        if ($apiKey === '') {
            throw new BrevoDeliveryException(
                'Brevo transactional email API key is not configured.',
                retryable: false,
                category: BrevoErrorCategory::Configuration,
            );
        }

        if ($senderEmail === '') {
            throw new BrevoDeliveryException(
                'Brevo transactional email sender is not configured.',
                retryable: false,
                category: BrevoErrorCategory::Configuration,
            );
        }

        if ($senderName === '') {
            $senderName = 'SYRTAK';
        }

        $payload = [
            'sender' => [
                'name' => $senderName,
                'email' => $senderEmail,
            ],
            'to' => [
                ['email' => $to],
            ],
            'subject' => $subject,
            'htmlContent' => $html,
        ];

        if ($text !== null && $text !== '') {
            $payload['textContent'] = $text;
        }

        try {
            $response = Http::withHeaders([
                'api-key' => $apiKey,
            ])
                ->acceptJson()
                ->asJson()
                ->withOptions([
                    'verify' => HttpTls::verify(),
                    'connect_timeout' => (float) config('services.brevo.connect_timeout_seconds', 5),
                    'timeout' => (float) config('services.brevo.timeout_seconds', 15),
                ])
                ->post(self::SEND_URL, $payload);
        } catch (ConnectionException $e) {
            $category = $this->classifyConnectionFailure($e);

            return BrevoSendResult::failure(
                0,
                $category,
                $this->transportReason($e),
            );
        } catch (Throwable $e) {
            Log::warning('otp.brevo_http_unexpected', [
                'provider' => 'brevo',
                'exception' => $e::class,
            ]);

            return BrevoSendResult::failure(0, BrevoErrorCategory::Unknown);
        }

        return $this->parseResponse($response);
    }

    private function parseResponse(Response $response): BrevoSendResult
    {
        $status = $response->status();

        if ($response->successful()) {
            $messageId = $response->json('messageId');

            return BrevoSendResult::success(
                $status,
                is_string($messageId) && $messageId !== '' ? $messageId : null,
            );
        }

        return BrevoSendResult::failure($status, $this->classifyHttpFailure($status, $response));
    }

    private function classifyHttpFailure(int $status, Response $response): BrevoErrorCategory
    {
        $code = strtolower((string) $response->json('code'));

        if (in_array($status, [401, 403], true) || in_array($code, ['unauthorized', 'permission_denied'], true)) {
            return BrevoErrorCategory::Authentication;
        }

        if ($status === 429) {
            return BrevoErrorCategory::RateLimit;
        }

        if ($status >= 500) {
            return BrevoErrorCategory::Server;
        }

        if ($status >= 400 && $status < 500) {
            return BrevoErrorCategory::Validation;
        }

        return BrevoErrorCategory::Unknown;
    }

    private function classifyConnectionFailure(ConnectionException $exception): BrevoErrorCategory
    {
        $message = strtolower($exception->getMessage());

        if (preg_match('/curl error (60|77)\b/', $message)
            || str_contains($message, 'ssl certificate problem')
            || str_contains($message, 'unable to get local issuer')
            || str_contains($message, 'error setting certificate verify locations')) {
            return BrevoErrorCategory::Ssl;
        }

        if (str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28')) {
            return BrevoErrorCategory::Timeout;
        }

        return BrevoErrorCategory::Connection;
    }

    private function transportReason(ConnectionException $exception): ?string
    {
        if (preg_match('/curl error (\d+)/i', $exception->getMessage(), $matches)) {
            return 'curl_'.$matches[1];
        }

        return null;
    }
}
