<?php

namespace Tests\Feature;

use App\Modules\Firebase\Services\FirebaseAccessTokenProvider;
use App\Modules\Firebase\Services\FirebaseCredentialsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeFirebaseCredentials;
use Tests\TestCase;

class FirebaseAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64(),
            'firebase.oauth.scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'firebase.oauth.cache_key' => 'firebase.oauth.access_token.test',
            'firebase.oauth.refresh_skew_seconds' => 60,
        ]);

        Cache::forget('firebase.oauth.access_token.test');
    }

    public function test_google_auth_credentials_use_firebase_messaging_scope(): void
    {
        $credentials = app(FirebaseCredentialsProvider::class)->createServiceAccountCredentials();

        $reflection = new \ReflectionClass($credentials);
        $authProp = $reflection->getProperty('auth');
        $authProp->setAccessible(true);
        $oauth2 = $authProp->getValue($credentials);

        $scope = $oauth2->getScope();
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $scope);
    }

    public function test_access_token_is_returned_and_cached(): void
    {
        $calls = 0;
        $provider = new FirebaseAccessTokenProvider(
            app(FirebaseCredentialsProvider::class),
            function () use (&$calls) {
                $calls++;

                return [
                    'access_token' => 'cached-access-token-'.$calls,
                    'expires_in' => 3600,
                ];
            }
        );

        $first = $provider->getAccessToken();
        $second = $provider->getAccessToken();

        $this->assertSame('cached-access-token-1', $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);

        $cached = Cache::get('firebase.oauth.access_token.test');
        $this->assertIsArray($cached);
        $this->assertSame('cached-access-token-1', $cached['access_token']);
        $this->assertArrayHasKey('expires_at', $cached);
        $this->assertArrayNotHasKey('private_key', $cached);
        $this->assertArrayNotHasKey('credentials', $cached);
    }

    public function test_nearly_expired_token_is_refreshed(): void
    {
        $calls = 0;
        $provider = new FirebaseAccessTokenProvider(
            app(FirebaseCredentialsProvider::class),
            function () use (&$calls) {
                $calls++;

                return [
                    'access_token' => 'token-'.$calls,
                    'expires_in' => 30, // less than refresh skew (60) → immediate refresh next call
                ];
            }
        );

        $first = $provider->getAccessToken();
        $this->assertSame('token-1', $first);

        // With expires_in=30 and skew=60, TTL becomes max(1, 30-60)=1 second.
        // Force expiry by putting an already-near-expiry cache entry.
        Cache::put('firebase.oauth.access_token.test', [
            'access_token' => 'token-1',
            'expires_at' => now()->getTimestamp() + 30,
        ], now()->addSeconds(30));

        $second = $provider->getAccessToken();
        $this->assertSame('token-2', $second);
        $this->assertSame(2, $calls);
    }

    public function test_expired_token_is_refreshed_using_expires_at_metadata(): void
    {
        $calls = 0;
        $provider = new FirebaseAccessTokenProvider(
            app(FirebaseCredentialsProvider::class),
            function () use (&$calls) {
                $calls++;

                return [
                    'access_token' => 'meta-token-'.$calls,
                    'expires_at' => now()->getTimestamp() + 3600,
                ];
            }
        );

        $this->assertSame('meta-token-1', $provider->getAccessToken());

        Cache::put('firebase.oauth.access_token.test', [
            'access_token' => 'meta-token-1',
            'expires_at' => now()->getTimestamp() - 1,
        ], now()->addMinute());

        $this->assertSame('meta-token-2', $provider->getAccessToken());
        $this->assertSame(2, $calls);
    }
}
