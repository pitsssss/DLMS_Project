<?php

namespace App\Modules\Firebase\Services;

use App\Modules\Firebase\Exceptions\FirebaseCredentialsException;
use Google\Auth\Credentials\ServiceAccountCredentials;
use JsonException;

/**
 * Decodes Base64 Firebase service-account JSON in memory only.
 * Never writes credentials to disk, cache, logs, or the database.
 */
class FirebaseCredentialsProvider
{
    private const REQUIRED_FIELDS = [
        'type',
        'project_id',
        'private_key_id',
        'private_key',
        'client_email',
        'client_id',
        'token_uri',
    ];

    /**
     * @return array<string, mixed>
     */
    public function decodeServiceAccount(): array
    {
        $projectId = trim((string) config('firebase.project_id', ''));
        $encoded = (string) config('firebase.credentials_base64', '');

        if ($projectId === '') {
            throw new FirebaseCredentialsException('FIREBASE_PROJECT_ID is not configured.');
        }

        if (trim($encoded) === '') {
            throw new FirebaseCredentialsException('FIREBASE_CREDENTIALS_BASE64 is not configured.');
        }

        $json = $this->decodeBase64Json($encoded);
        $this->validateStructure($json);
        $this->assertProjectMatch($projectId, (string) $json['project_id']);

        return $json;
    }

    public function configuredProjectId(): string
    {
        $projectId = trim((string) config('firebase.project_id', ''));
        if ($projectId === '') {
            throw new FirebaseCredentialsException('FIREBASE_PROJECT_ID is not configured.');
        }

        return $projectId;
    }

    public function createServiceAccountCredentials(): ServiceAccountCredentials
    {
        $json = $this->decodeServiceAccount();
        $scope = (string) config('firebase.oauth.scope');

        try {
            return new ServiceAccountCredentials($scope, $json);
        } catch (\Throwable $e) {
            throw new FirebaseCredentialsException(
                'Firebase service-account credentials are invalid.',
                0,
                $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBase64Json(string $encoded): array
    {
        $normalized = preg_replace('/\s+/', '', $encoded) ?? $encoded;
        $decoded = base64_decode($normalized, true);

        if ($decoded === false || $decoded === '') {
            throw new FirebaseCredentialsException('FIREBASE_CREDENTIALS_BASE64 is not valid Base64.');
        }

        try {
            $json = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FirebaseCredentialsException('FIREBASE_CREDENTIALS_BASE64 does not contain valid JSON.');
        }

        if (! is_array($json)) {
            throw new FirebaseCredentialsException('FIREBASE_CREDENTIALS_BASE64 JSON must be an object.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function validateStructure(array $json): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $json) || $json[$field] === null || $json[$field] === '') {
                throw new FirebaseCredentialsException(
                    "Firebase service-account credentials are missing required field: {$field}."
                );
            }
        }

        if (($json['type'] ?? null) !== 'service_account') {
            throw new FirebaseCredentialsException(
                'Firebase credentials type must be service_account.'
            );
        }

        $privateKey = (string) $json['private_key'];
        if (! str_contains($privateKey, 'BEGIN') || ! str_contains($privateKey, 'PRIVATE KEY')) {
            throw new FirebaseCredentialsException(
                'Firebase service-account private_key is not a valid PEM private key.'
            );
        }
    }

    private function assertProjectMatch(string $configuredProjectId, string $credentialProjectId): void
    {
        if ($configuredProjectId !== $credentialProjectId) {
            throw new FirebaseCredentialsException(
                'Firebase credentials project_id does not match FIREBASE_PROJECT_ID.'
            );
        }
    }
}
