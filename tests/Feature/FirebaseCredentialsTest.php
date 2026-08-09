<?php

namespace Tests\Feature;

use App\Modules\Firebase\Exceptions\FirebaseCredentialsException;
use App\Modules\Firebase\Services\FirebaseCredentialsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFirebaseCredentials;
use Tests\TestCase;

class FirebaseCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_project_id_fails_safely(): void
    {
        config([
            'firebase.project_id' => '',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64(),
        ]);

        $this->expectException(FirebaseCredentialsException::class);
        $this->expectExceptionMessage('FIREBASE_PROJECT_ID is not configured.');

        app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
    }

    public function test_missing_credentials_fails_safely(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => '',
        ]);

        $this->expectException(FirebaseCredentialsException::class);
        $this->expectExceptionMessage('FIREBASE_CREDENTIALS_BASE64 is not configured.');

        app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
    }

    public function test_invalid_base64_fails(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => '%%%not-base64%%%',
        ]);

        try {
            app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
            $this->fail('Expected FirebaseCredentialsException');
        } catch (FirebaseCredentialsException $e) {
            $this->assertStringContainsString('Base64', $e->getMessage());
            $this->assertStringNotContainsString('%%%', $e->getMessage());
            $this->assertStringNotContainsString('BEGIN', $e->getMessage());
        }
    }

    public function test_invalid_json_fails(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => base64_encode('not-json'),
        ]);

        $this->expectException(FirebaseCredentialsException::class);
        $this->expectExceptionMessage('valid JSON');

        app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
    }

    public function test_wrong_credential_type_fails(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64([
                'type' => 'authorized_user',
            ]),
        ]);

        $this->expectException(FirebaseCredentialsException::class);
        $this->expectExceptionMessage('service_account');

        app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
    }

    public function test_missing_required_fields_fails(): void
    {
        $json = FakeFirebaseCredentials::serviceAccount();
        unset($json['client_email']);

        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => base64_encode(json_encode($json, JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(FirebaseCredentialsException::class);
        $this->expectExceptionMessage('client_email');

        app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
    }

    public function test_project_mismatch_fails(): void
    {
        config([
            'firebase.project_id' => 'configured-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64([
                'project_id' => 'other-project',
            ]),
        ]);

        try {
            app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
            $this->fail('Expected FirebaseCredentialsException');
        } catch (FirebaseCredentialsException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $e->getMessage());
            $this->assertStringNotContainsString(FakeFirebaseCredentials::base64(), $e->getMessage());
        }
    }

    public function test_valid_credentials_decode_and_never_expose_private_key_in_exception_messages(): void
    {
        $encoded = FakeFirebaseCredentials::base64();
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => $encoded,
        ]);

        $decoded = app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
        $this->assertSame('service_account', $decoded['type']);
        $this->assertSame('test-project', $decoded['project_id']);
        $this->assertArrayHasKey('private_key', $decoded);

        try {
            config(['firebase.credentials_base64' => FakeFirebaseCredentials::base64(['type' => 'bad'])]);
            app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
        } catch (FirebaseCredentialsException $e) {
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $e->getMessage());
            $this->assertStringNotContainsString($encoded, $e->getMessage());
            $this->assertStringNotContainsString((string) $decoded['private_key'], $e->getMessage());
        }
    }

    public function test_invalid_private_key_pem_fails(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64([
                'private_key' => 'not-a-pem',
            ]),
        ]);

        $this->expectException(FirebaseCredentialsException::class);
        $this->expectExceptionMessage('private_key');

        app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
    }

    public function test_credentials_are_not_written_to_disk(): void
    {
        config([
            'firebase.project_id' => 'test-project',
            'firebase.credentials_base64' => FakeFirebaseCredentials::base64(),
        ]);

        $before = $this->listSensitivePaths();
        app(FirebaseCredentialsProvider::class)->decodeServiceAccount();
        $after = $this->listSensitivePaths();

        $this->assertSame($before, $after);
    }

    /**
     * @return list<string>
     */
    private function listSensitivePaths(): array
    {
        $paths = [
            storage_path('app'),
            storage_path('framework/cache'),
            storage_path('logs'),
            sys_get_temp_dir(),
        ];

        $snapshot = [];
        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                if (str_contains(strtolower($name), 'firebase')
                    || str_contains(strtolower($name), 'service.account')
                    || str_ends_with($name, '.json')) {
                    $snapshot[] = $file->getPathname().':'.$file->getMTime();
                }
            }
        }

        sort($snapshot);

        return $snapshot;
    }
}
