<?php

namespace App\Modules\Push\Repositories;

use App\Enums\PushDeliveryStatus;
use App\Models\PushDelivery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class PushDeliveryRepository
{
    public function findById(int $id, bool $lock = false): ?PushDelivery
    {
        $query = PushDelivery::query()->whereKey($id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Create delivery if missing. Returns [delivery, created].
     *
     * @return array{0: PushDelivery, 1: bool}
     */
    public function firstOrCreateForNotificationDevice(int $notificationId, int $pushDeviceId): array
    {
        $key = PushDelivery::deliveryKey($notificationId, $pushDeviceId);

        $existing = PushDelivery::query()->where('delivery_key', $key)->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $delivery = PushDelivery::query()->create([
                'notification_id' => $notificationId,
                'push_device_id' => $pushDeviceId,
                'delivery_key' => $key,
                'status' => PushDeliveryStatus::Pending,
                'attempts' => 0,
            ]);

            return [$delivery, true];
        } catch (QueryException $e) {
            $existing = PushDelivery::query()->where('delivery_key', $key)->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            throw $e;
        }
    }

    /**
     * @return Collection<int, PushDelivery>
     */
    public function pendingBatch(int $limit): Collection
    {
        return PushDelivery::query()
            ->where('status', PushDeliveryStatus::Pending)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * IDs of processing deliveries whose lease (last_attempt_at) has expired.
     *
     * @return list<int>
     */
    public function staleProcessingIds(int $leaseSeconds, int $limit): array
    {
        $cutoff = now()->subSeconds(max(60, $leaseSeconds));

        return PushDelivery::query()
            ->where('status', PushDeliveryStatus::Processing)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_attempt_at')
                    ->orWhere('last_attempt_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
