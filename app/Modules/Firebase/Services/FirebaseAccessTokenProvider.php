<?php

namespace App\Modules\Firebase\Services;

use App\Modules\Firebase\Exceptions\FirebaseCredentialsException;
use App\Modules\Firebase\Support\FirebaseTls;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Obtains short-lived Google OAuth access tokens for FCM and caches them safely.
 * Cache stores only access_token + expires_at — never private keys or JSON credentials.
 */
class FirebaseAccessTokenProvider
{
    /**
     * @param  (callable(): array{access_token?: string, expires_in?: int|string, expires_at?: int|string})|null  $tokenFetcher
     */
    public function __construct(
        private readonly FirebaseCredentialsProvider $credentials,
        private readonly mixed $tokenFetcher = null,
    ) {}

    public function getAccessToken(): string
    {
        $cacheKey = (string) config('firebase.oauth.cache_key', 'firebase.oauth.access_token.v1');
        $skew = max(0, (int) config('firebase.oauth.refresh_skew_seconds', 60));

        $cached = Cache::get($cacheKey);
        if (is_array($cached)
            && isset($cached['access_token'], $cached['expires_at'])
            && is_string($cached['access_token'])
            && $cached['access_token'] !== ''
            && (int) $cached['expires_at'] > (now()->getTimestamp() + $skew)
        ) {
            return $cached['access_token'];
        }

        $tokenPayload = $this->fetchTokenPayload();
        $accessToken = (string) ($tokenPayload['access_token'] ?? '');
        if ($accessToken === '') {
            throw new FirebaseCredentialsException('Google OAuth access token retrieval returned an empty token.');
        }

        $expiresAt = $this->resolveExpiresAt($tokenPayload);
        $ttlSeconds = max(1, $expiresAt - now()->getTimestamp() - $skew);

        Cache::put($cacheKey, [
            'access_token' => $accessToken,
            'expires_at' => $expiresAt,
        ], now()->addSeconds($ttlSeconds));

        return $accessToken;
    }

    public function clearCachedToken(): void
    {
        Cache::forget((string) config('firebase.oauth.cache_key', 'firebase.oauth.access_token.v1'));
    }

    /**
     * @return array{access_token?: string, expires_in?: int|string, expires_at?: int|string}
     */
    private function fetchTokenPayload(): array
    {
        try {
            if (is_callable($this->tokenFetcher)) {
                /** @var array{access_token?: string, expires_in?: int|string, expires_at?: int|string} $payload */
                $payload = ($this->tokenFetcher)();

                return $payload;
            }

            $credentials = $this->credentials->createServiceAccountCredentials();
            $httpHandler = HttpHandlerFactory::build(new Client([
                'verify' => FirebaseTls::verify(),
                'timeout' => (float) config('firebase.fcm.timeout_seconds', 15),
                'connect_timeout' => (float) config('firebase.fcm.connect_timeout_seconds', 5),
            ]));
            $payload = $credentials->fetchAuthToken($httpHandler);

            if (! is_array($payload)) {
                throw new FirebaseCredentialsException('Google OAuth access token retrieval failed.');
            }

            return $payload;
        } catch (FirebaseCredentialsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FirebaseCredentialsException(
                'Google OAuth access token retrieval failed.'.$this->safeFailureHint($e),
                0,
                $e
            );
        }
    }

    private function safeFailureHint(Throwable $e): string
    {
        $haystack = strtolower($e->getMessage());
        $prev = $e->getPrevious();
        while ($prev instanceof Throwable) {
            $haystack .= ' '.strtolower($prev->getMessage());
            $prev = $prev->getPrevious();
        }

        if (str_contains($haystack, 'curl error 60')
            || str_contains($haystack, 'ssl certificate')
            || str_contains($haystack, 'certificate verify failed')
            || str_contains($haystack, 'ssl peer certificate')) {
            return ' (TLS/SSL CA verification failed — set FIREBASE_CA_BUNDLE or php.ini curl.cainfo)';
        }

        if (str_contains($haystack, 'timed out') || str_contains($haystack, 'could not resolve')) {
            return ' (network)';
        }

        if (str_contains($haystack, 'openssl') || str_contains($haystack, 'signing')) {
            return ' (JWT signing/openssl)';
        }

        return '';
    }

    /**
     * @param  array{access_token?: string, expires_in?: int|string, expires_at?: int|string}  $tokenPayload
     */
    private function resolveExpiresAt(array $tokenPayload): int
    {
        if (isset($tokenPayload['expires_at']) && is_numeric($tokenPayload['expires_at'])) {
            return (int) $tokenPayload['expires_at'];
        }

        if (isset($tokenPayload['expires_in']) && is_numeric($tokenPayload['expires_in'])) {
            return now()->getTimestamp() + max(1, (int) $tokenPayload['expires_in']);
        }

        // Fallback only when Google omits expiry metadata (should be rare).
        return now()->getTimestamp() + 3500;
    }
}
