<?php

namespace App\Modules\Devices\Services;

use App\Models\PushDevice;
use App\Models\User;
use App\Modules\Devices\Repositories\PushDeviceRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Citizen FCM push-device registration (F1).
 *
 * Stores encrypted FCM tokens for future delivery. Does not send push.
 *
 * Upsert / reassignment policy:
 * - Same user + device_id + token → refresh last_registered_at (idempotent).
 * - Same user + device_id + new token → rotate token on that row.
 * - Same user + new device_id + new token → additional device row.
 * - Same token_hash already on another row for same user → reconcile to one
 *   canonical row keyed by the submitted device_id.
 * - Token belongs to another user → atomically reassign to the current citizen
 *   (typical logout/login on shared installation). Previous owner loses the token.
 *   No previous-owner details are returned.
 */
class PushDeviceService
{
    private const MAX_UNIQUE_RETRIES = 3;

    public function __construct(
        private readonly PushDeviceRepository $devices,
    ) {}

    /**
     * @param  array{device_id: string, platform: string, token: string}  $data
     */
    public function register(User $user, array $data): PushDevice
    {
        $deviceId = $data['device_id'];
        $platform = $data['platform'];
        $token = $data['token'];
        $tokenHash = $this->hashToken($token);

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(function () use ($user, $deviceId, $platform, $token, $tokenHash) {
                    $byDevice = $this->devices->findByUserAndDeviceId($user->id, $deviceId, lock: true);
                    $byToken = $this->devices->findByTokenHash($tokenHash, lock: true);

                    if ($byDevice !== null && $byToken !== null && $byDevice->id === $byToken->id) {
                        return $this->refreshRegistration($byDevice, $platform);
                    }

                    if ($byDevice !== null && $byToken === null) {
                        return $this->applyTokenRotation($byDevice, $platform, $token, $tokenHash);
                    }

                    if ($byDevice !== null && $byToken !== null && $byDevice->id !== $byToken->id) {
                        return $this->mergeDeviceAndTokenRows(
                            $byDevice,
                            $byToken,
                            $user->id,
                            $platform,
                            $token,
                            $tokenHash
                        );
                    }

                    if ($byDevice === null && $byToken !== null) {
                        return $this->claimTokenRow(
                            $byToken,
                            $user->id,
                            $deviceId,
                            $platform,
                            $token,
                            $tokenHash
                        );
                    }

                    return $this->devices->create([
                        'user_id' => $user->id,
                        'device_id' => $deviceId,
                        'platform' => $platform,
                        'token' => $token,
                        'token_hash' => $tokenHash,
                        'last_registered_at' => now(),
                    ]);
                });
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $attempt >= self::MAX_UNIQUE_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Idempotent unregister of the current citizen's device_id only.
     */
    public function unregister(User $user, string $deviceId): void
    {
        $this->devices->deleteForUserDevice($user->id, $deviceId);
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function refreshRegistration(PushDevice $device, string $platform): PushDevice
    {
        $device->platform = $platform;
        $device->last_registered_at = now();
        $device->save();

        return $device->fresh();
    }

    private function applyTokenRotation(
        PushDevice $device,
        string $platform,
        string $token,
        string $tokenHash,
    ): PushDevice {
        $device->platform = $platform;
        $device->token = $token;
        $device->token_hash = $tokenHash;
        $device->last_registered_at = now();
        $device->save();

        return $device->fresh();
    }

    /**
     * Device row and token row differ — keep the installation (device_id) row as canonical.
     */
    private function mergeDeviceAndTokenRows(
        PushDevice $byDevice,
        PushDevice $byToken,
        int $userId,
        string $platform,
        string $token,
        string $tokenHash,
    ): PushDevice {
        $this->devices->deleteById($byToken->id);

        $byDevice->user_id = $userId;
        $byDevice->platform = $platform;
        $byDevice->token = $token;
        $byDevice->token_hash = $tokenHash;
        $byDevice->last_registered_at = now();
        $byDevice->save();

        return $byDevice->fresh();
    }

    /**
     * Token exists on another row (same or other user); no current device_id row.
     * Reassign that row to the current installation identity.
     */
    private function claimTokenRow(
        PushDevice $byToken,
        int $userId,
        string $deviceId,
        string $platform,
        string $token,
        string $tokenHash,
    ): PushDevice {
        // If another device already occupies (user_id, device_id) after race, unique
        // constraint + retry will reconcile. Prefer updating the token row in place.
        $byToken->user_id = $userId;
        $byToken->device_id = $deviceId;
        $byToken->platform = $platform;
        $byToken->token = $token;
        $byToken->token_hash = $tokenHash;
        $byToken->last_registered_at = now();
        $byToken->save();

        return $byToken->fresh();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $sqlState === '23000'
            || $driverCode === '1062'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
