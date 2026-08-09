<?php

namespace Tests\Feature;

use App\Modules\Firebase\Exceptions\FirebaseCredentialsException;
use App\Modules\Firebase\Services\FirebaseAccessTokenProvider;
use App\Modules\Firebase\Services\FirebaseCredentialsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFirebaseCredentials;
use Tests\TestCase;

class FirebaseConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_keys_exist_and_read_from_config_not_runtime_env_helpers_in_services(): void
    {
        $this->assertTrue(array_key_exists('project_id', config('firebase')));
        $this->assertTrue(array_key_exists('credentials_base64', config('firebase')));
        $this->assertSame(
            'https://www.googleapis.com/auth/firebase.messaging',
            config('firebase.oauth.scope')
        );
        $this->assertSame('https://fcm.googleapis.com', config('firebase.fcm.base_uri'));

        $files = [
            app_path('Modules/Firebase/Services/FirebaseCredentialsProvider.php'),
            app_path('Modules/Firebase/Services/FirebaseAccessTokenProvider.php'),
            app_path('Modules/Firebase/Services/FcmClient.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('env(', $source);
            $this->assertStringNotContainsString('$_ENV', $source);
            $this->assertStringNotContainsString('getenv(', $source);
        }
    }

    public function test_verification_command_never_prints_secrets_on_failure(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64([
                'project_id' => 'mismatch-project',
            ]),
        ]);

        $this->artisan('firebase:verify')
            ->expectsOutputToContain('Firebase verification failed')
            ->doesntExpectOutputToContain('BEGIN PRIVATE KEY')
            ->doesntExpectOutputToContain('private_key')
            ->assertFailed();
    }

    public function test_verification_command_success_output_hides_access_token(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64(),
            'firebase.oauth.cache_key' => 'firebase.oauth.verify_cmd_test',
        ]);

        $this->app->bind(FirebaseAccessTokenProvider::class, function () {
            return new FirebaseAccessTokenProvider(
                app(FirebaseCredentialsProvider::class),
                fn () => [
                    'access_token' => 'MUST-NOT-APPEAR-IN-OUTPUT',
                    'expires_in' => 3600,
                ]
            );
        });

        $this->artisan('firebase:verify')
            ->expectsOutput('Firebase credentials: OK')
            ->expectsOutput('Project: test-project')
            ->expectsOutput('OAuth authentication: OK')
            ->doesntExpectOutputToContain('MUST-NOT-APPEAR-IN-OUTPUT')
            ->doesntExpectOutputToContain('BEGIN PRIVATE KEY')
            ->assertSuccessful();
    }

    public function test_verification_command_fails_when_oauth_empty(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64(),
            'firebase.oauth.cache_key' => 'firebase.oauth.verify_empty_test',
        ]);

        $this->app->bind(FirebaseAccessTokenProvider::class, function () {
            return new FirebaseAccessTokenProvider(
                app(FirebaseCredentialsProvider::class),
                fn () => [
                    'access_token' => '',
                    'expires_in' => 3600,
                ]
            );
        });

        $this->artisan('firebase:verify')
            ->assertFailed();
    }

    public function test_missing_config_surfaces_as_credentials_exception_category(): void
    {
        config([
            'firebase.project_id' => null,
            'firebase.credentials_base64' => null,
        ]);

        $this->expectException(FirebaseCredentialsException::class);
        app(FirebaseCredentialsProvider::class)->configuredProjectId();
    }
}
