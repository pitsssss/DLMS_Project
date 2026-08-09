<?php

namespace App\Console\Commands;

use App\Modules\Firebase\Exceptions\FirebaseCredentialsException;
use App\Modules\Firebase\Services\FirebaseAccessTokenProvider;
use App\Modules\Firebase\Services\FirebaseCredentialsProvider;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ops/local verification of Firebase service-account OAuth (no FCM send).
 * Never prints access tokens, private keys, or Base64 credentials.
 */
class FirebaseVerifyCommand extends Command
{
    protected $signature = 'firebase:verify';

    protected $description = 'Validate Firebase service-account credentials and obtain a Google OAuth access token (safe output only)';

    public function handle(
        FirebaseCredentialsProvider $credentials,
        FirebaseAccessTokenProvider $tokens,
    ): int {
        try {
            $projectId = $credentials->configuredProjectId();
            $credentials->decodeServiceAccount();
            $this->info('Firebase credentials: OK');
            $this->line("Project: {$projectId}");

            $tokens->clearCachedToken();
            $accessToken = $tokens->getAccessToken();

            if ($accessToken === '') {
                $this->error('OAuth authentication: FAILED (empty token)');

                return self::FAILURE;
            }

            $this->info('OAuth authentication: OK');

            return self::SUCCESS;
        } catch (FirebaseCredentialsException $e) {
            $this->error('Firebase verification failed: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Firebase verification failed: unexpected error.');

            return self::FAILURE;
        }
    }
}
