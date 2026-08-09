<?php

namespace Tests\Feature;

use App\Modules\Firebase\Services\FcmClient;
use App\Modules\Firebase\Services\FirebaseAccessTokenProvider;
use App\Modules\Firebase\Services\FirebaseCredentialsProvider;
use App\Modules\Firebase\Support\FcmErrorCategory;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\Support\FakeFirebaseCredentials;
use Tests\TestCase;

class FcmClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64(),
            'firebase.fcm.base_uri' => 'https://fcm.googleapis.com',
            'firebase.oauth.cache_key' => 'firebase.oauth.access_token.fcm_test',
        ]);
    }

    public function test_endpoint_uses_configured_project_and_bearer_header(): void
    {
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
                'name' => 'projects/test-project/messages/abc123',
            ], 200),
        ]);

        $client = $this->clientWithToken('oauth-test-token');
        $result = $client->sendToToken('device-fcm-token', 'Title', 'Body', [
            'type' => 'fine.created',
            'fine_id' => 42,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('projects/test-project/messages/abc123', $result->messageName);
        $this->assertSame(200, $result->httpStatus);

        Http::assertSent(function ($request) {
            $this->assertSame(
                'https://fcm.googleapis.com/v1/projects/test-project/messages:send',
                (string) $request->url()
            );
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer oauth-test-token'));
            $this->assertSame('device-fcm-token', $request['message']['token']);
            $this->assertSame('Title', $request['message']['notification']['title']);
            $this->assertSame('Body', $request['message']['notification']['body']);
            $this->assertSame('fine.created', $request['message']['data']['type']);
            $this->assertSame('42', $request['message']['data']['fine_id']);

            return true;
        });
    }

    public function test_validate_only_flag_is_sent(): void
    {
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/0'], 200),
        ]);

        $result = $this->clientWithToken('t')->sendToToken('tok', 'T', 'B', [], validateOnly: true);
        $this->assertTrue($result->success);
        $this->assertTrue($result->validateOnly);

        Http::assertSent(fn ($request) => ($request['validate_only'] ?? false) === true);
    }

    public function test_auth_error_is_classified(): void
    {
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 401,
                    'message' => 'Request had invalid authentication credentials.',
                    'status' => 'UNAUTHENTICATED',
                ],
            ], 401),
        ]);

        $result = $this->clientWithToken('bad')->sendToToken('tok', 'T', 'B');
        $this->assertFalse($result->success);
        $this->assertSame(FcmErrorCategory::Authentication, $result->errorCategory);
        $this->assertFalse($result->retryable);
    }

    public function test_invalid_argument_is_classified(): void
    {
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'Invalid JSON payload',
                    'status' => 'INVALID_ARGUMENT',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'INVALID_ARGUMENT',
                    ]],
                ],
            ], 400),
        ]);

        $result = $this->clientWithToken('t')->sendToToken('tok', 'T', 'B');
        $this->assertSame(FcmErrorCategory::InvalidArgument, $result->errorCategory);
        $this->assertSame('INVALID_ARGUMENT', $result->providerErrorCode);
    }

    public function test_unregistered_error_is_classified(): void
    {
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 404,
                    'message' => 'Requested entity was not found.',
                    'status' => 'NOT_FOUND',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ]],
                ],
            ], 404),
        ]);

        $result = $this->clientWithToken('t')->sendToToken('dead-token', 'T', 'B');
        $this->assertSame(FcmErrorCategory::Unregistered, $result->errorCategory);
        $this->assertTrue($result->invalidToken);
        $this->assertFalse($result->retryable);
    }

    public function test_temporary_server_error_is_classified_retryable(): void
    {
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'Unavailable',
                    'status' => 'UNAVAILABLE',
                ],
            ], 503),
        ]);

        $result = $this->clientWithToken('t')->sendToToken('tok', 'T', 'B');
        $this->assertSame(FcmErrorCategory::Server, $result->errorCategory);
        $this->assertTrue($result->retryable);
    }

    public function test_authorization_header_and_token_are_not_logged(): void
    {
        Log::spy();

        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 404,
                    'status' => 'NOT_FOUND',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ]],
                ],
            ], 404),
        ]);

        $deviceToken = 'super-secret-device-fcm-token';
        $this->clientWithToken('oauth-secret-access-token')
            ->sendToToken($deviceToken, 'T', 'B');

        Log::shouldHaveReceived('warning')->atLeast()->once();
        Log::shouldNotHaveReceived('warning', function (...$args) use ($deviceToken) {
            $encoded = json_encode($args);

            return str_contains((string) $encoded, $deviceToken)
                || str_contains((string) $encoded, 'oauth-secret-access-token')
                || str_contains((string) $encoded, 'Authorization');
        });
    }

    public function test_no_public_fcm_route_exists(): void
    {
        $this->postJson('/api/firebase/test')->assertNotFound();
        $this->postJson('/api/push/test')->assertNotFound();
        $this->postJson('/api/notifications/send-test')->assertNotFound();
    }

    public function test_notification_service_is_not_wired_to_fcm(): void
    {
        $ref = new ReflectionClass(NotificationService::class);
        $source = file_get_contents($ref->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('FcmClient', $source);
        $this->assertStringNotContainsString('FirebaseAccessTokenProvider', $source);
        $this->assertStringNotContainsString('firebase.messaging', $source);
    }

    private function clientWithToken(string $accessToken): FcmClient
    {
        $tokens = new FirebaseAccessTokenProvider(
            app(FirebaseCredentialsProvider::class),
            fn () => [
                'access_token' => $accessToken,
                'expires_in' => 3600,
            ]
        );

        return new FcmClient($tokens, app(FirebaseCredentialsProvider::class));
    }
}
