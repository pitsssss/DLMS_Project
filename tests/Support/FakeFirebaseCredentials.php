<?php

namespace Tests\Support;

/**
 * Fake Firebase service-account fixtures for offline tests.
 * Never uses real FIREBASE_CREDENTIALS_BASE64 from the environment.
 *
 * The embedded RSA key is a disposable test fixture only (not a production secret).
 */
final class FakeFirebaseCredentials
{
    /**
     * Disposable RSA private key for offline fixtures (not used in production).
     */
    private const TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKB
wEiOfCvDwxJwBXJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJ
hZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJh
Z5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ
5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5
bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5b
WqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpAgMBAAECggEABWM2C3rQYJYQZQZQZQZQZQ
ZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQ
ZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQ
ZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQ
ZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQ
ZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQKBgQD2qZQzZQZQZQZQZQZQZQZQ
ZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQ
ZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQZQ
ZQZQZQZQZQKBgQDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD
DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD
DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD
DDDDDDDDDQKBgQC7VJTUt9Us8cKBwEiOfCvDwxJwBXJk0Vj0uJhZ5bWqzqVxwKpJ
k0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk
0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0
Vj0uJhZ5bWqzqVxwKBgQDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD
DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD
DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD
DDDDDDDDDQKBgQC7VJTUt9Us8cKBwEiOfCvDwxJwBXJk0Vj0uJhZ5bWqzqVxwKpJ
k0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk
0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0Vj0uJhZ5bWqzqVxwKpJk0
Vj0uJhZ5bQ==
-----END PRIVATE KEY-----
PEM;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function serviceAccount(array $overrides = []): array
    {
        return array_merge([
            'type' => 'service_account',
            'project_id' => 'test-project',
            'private_key_id' => 'test-private-key-id',
            'private_key' => self::TEST_PRIVATE_KEY,
            'client_email' => 'firebase-adminsdk@test-project.iam.gserviceaccount.com',
            'client_id' => '123456789012345678901',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk%40test-project.iam.gserviceaccount.com',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function base64(array $overrides = []): string
    {
        return base64_encode(json_encode(self::serviceAccount($overrides), JSON_THROW_ON_ERROR));
    }
}
