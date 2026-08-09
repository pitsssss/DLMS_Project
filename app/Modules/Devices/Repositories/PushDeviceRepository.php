<?php

namespace App\Modules\Devices\Repositories;

use App\Models\PushDevice;

class PushDeviceRepository
{
    public function findByUserAndDeviceId(int $userId, string $deviceId, bool $lock = false): ?PushDevice
    {
        $query = PushDevice::query()
            ->where('user_id', $userId)
            ->where('device_id', $deviceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function findByTokenHash(string $tokenHash, bool $lock = false): ?PushDevice
    {
        $query = PushDevice::query()->where('token_hash', $tokenHash);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array{
     *     user_id: int,
     *     device_id: string,
     *     platform: string,
     *     token: string,
     *     token_hash: string,
     *     last_registered_at: mixed
     * }  $attributes
     */
    public function create(array $attributes): PushDevice
    {
        return PushDevice::query()->create($attributes);
    }

    public function deleteForUserDevice(int $userId, string $deviceId): int
    {
        return PushDevice::query()
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->delete();
    }

    public function deleteById(int $id): void
    {
        PushDevice::query()->whereKey($id)->delete();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PushDevice>
     */
    public function listForUser(int $userId)
    {
        return PushDevice::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();
    }

    public function findById(int $id, bool $lock = false): ?PushDevice
    {
        $query = PushDevice::query()->whereKey($id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Delete only if the current token_hash still matches (rotation-race safe).
     */
    public function deleteByIdAndTokenHash(int $id, string $tokenHash): int
    {
        return PushDevice::query()
            ->whereKey($id)
            ->where('token_hash', $tokenHash)
            ->delete();
    }
}
