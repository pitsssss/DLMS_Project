<?php

namespace App\Modules\Push\Services;

use App\Enums\PushDeliveryStatus;
use App\Jobs\SendPushNotificationJob;
use App\Models\Notification;
use App\Models\PushDelivery;
use App\Models\PushDevice;
use App\Modules\Devices\Repositories\PushDeviceRepository;
use App\Modules\Firebase\Services\FcmClient;
use App\Modules\Firebase\Support\FcmErrorCategory;
use App\Modules\Push\Repositories\PushDeliveryRepository;
use App\Modules\Push\Support\PushNotificationPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plans and executes per-device push deliveries.
 * Push is supplementary — failures never affect DB notifications or domain work.
 */
class PushDeliveryService
{
    public function __construct(
        private readonly PushDeliveryRepository $deliveries,
        private readonly PushDeviceRepository $devices,
        private readonly FcmClient $fcm,
    ) {}

    /**
     * Plan deliveries for a newly created notification only.
     * Callers must ensure the notification was genuinely created (not dedupe reuse).
     */
    public function planForNotification(Notification $notification): void
    {
        if (! $this->pushEnabled()) {
            return;
        }

        $devices = $this->devices->listForUser((int) $notification->user_id);
        if ($devices->isEmpty()) {
            return;
        }

        foreach ($devices as $device) {
            try {
                [$delivery, $created] = $this->deliveries->firstOrCreateForNotificationDevice(
                    (int) $notification->id,
                    (int) $device->id,
                );

                if ($created && $delivery->status === PushDeliveryStatus::Pending) {
                    $this->dispatchJobSafely($delivery->id);
                }
            } catch (Throwable $e) {
                Log::error('push.plan_device_failed', [
                    'notification_id' => $notification->id,
                    'push_device_id' => $device->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Redispatch pending deliveries and reclaim stale processing rows.
     *
     * @return array{dispatched: int, skipped: int, reclaimed: int}
     */
    public function dispatchPending(int $limit = 100): array
    {
        if (! $this->pushEnabled()) {
            return ['dispatched' => 0, 'skipped' => 0, 'reclaimed' => 0];
        }

        $reclaimed = $this->recoverStaleProcessing($limit);

        $dispatched = 0;
        $skipped = 0;

        foreach ($this->deliveries->pendingBatch($limit) as $delivery) {
            if ($delivery->status !== PushDeliveryStatus::Pending) {
                $skipped++;

                continue;
            }

            $this->dispatchJobSafely($delivery->id);
            $dispatched++;
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped, 'reclaimed' => $reclaimed];
    }

    /**
     * Reclaim processing rows whose lease (last_attempt_at) has expired.
     * Recent processing is never stolen.
     */
    public function recoverStaleProcessing(int $limit = 100): int
    {
        $leaseSeconds = max(60, (int) config('firebase.push.processing_lease_seconds', 180));
        $ids = $this->deliveries->staleProcessingIds($leaseSeconds, $limit);
        $reclaimed = 0;

        foreach ($ids as $id) {
            $ok = DB::transaction(function () use ($id, $leaseSeconds) {
                $delivery = $this->deliveries->findById($id, lock: true);
                if ($delivery === null || $delivery->status !== PushDeliveryStatus::Processing) {
                    return false;
                }

                $cutoff = now()->subSeconds($leaseSeconds);
                if ($delivery->last_attempt_at !== null && $delivery->last_attempt_at->gt($cutoff)) {
                    return false;
                }

                $delivery->status = PushDeliveryStatus::Pending;
                $delivery->save();

                return true;
            });

            if ($ok) {
                $reclaimed++;
            }
        }

        if ($reclaimed > 0) {
            Log::info('push.stale_processing_reclaimed', [
                'count' => $reclaimed,
                'lease_seconds' => $leaseSeconds,
            ]);
        }

        return $reclaimed;
    }

    /**
     * Process a single delivery (called by the queue job).
     *
     * @return array{outcome: string, retry_after_seconds?: int|null, fcm_attempts?: int}
     */
    public function processDelivery(int $pushDeliveryId): array
    {
        $claim = $this->claimDelivery($pushDeliveryId);
        if ($claim['outcome'] === 'skipped') {
            return ['outcome' => 'skipped'];
        }

        /** @var PushDelivery $delivery */
        $delivery = $claim['delivery'];
        $tokenHashUsed = $claim['token_hash'];
        /** @var string $token */
        $token = $claim['token'];
        /** @var Notification $notification */
        $notification = $claim['notification'];

        // Provider attempts count only real FCM calls.
        $delivery->attempts = $delivery->attempts + 1;
        $delivery->last_attempt_at = now();
        $delivery->save();

        $data = PushNotificationPayload::buildData(
            (int) $notification->id,
            $notification->type,
            is_array($notification->data) ? $notification->data : null,
        );

        $result = $this->fcm->sendToToken(
            $token,
            (string) $notification->title,
            (string) $notification->body,
            $data,
        );

        if ($result->success) {
            $delivery->status = PushDeliveryStatus::Sent;
            $delivery->provider_message_id = $result->messageName;
            $delivery->sent_at = now();
            $delivery->last_attempt_at = now();
            $delivery->last_error_category = null;
            $delivery->last_http_status = $result->httpStatus > 0 ? $result->httpStatus : null;
            $delivery->failed_at = null;
            $delivery->save();

            return ['outcome' => 'sent', 'fcm_attempts' => $delivery->attempts];
        }

        $delivery->last_error_category = $result->errorCategory?->value;
        $delivery->last_http_status = $result->httpStatus > 0 ? $result->httpStatus : null;
        $delivery->last_attempt_at = now();

        if ($result->invalidToken) {
            $delivery->status = PushDeliveryStatus::InvalidToken;
            $delivery->failed_at = now();
            $delivery->save();

            if ($delivery->push_device_id !== null && is_string($tokenHashUsed)) {
                $this->devices->deleteByIdAndTokenHash((int) $delivery->push_device_id, $tokenHashUsed);
            }

            return ['outcome' => 'invalid_token', 'fcm_attempts' => $delivery->attempts];
        }

        if ($result->retryable) {
            if ($delivery->attempts >= $this->maxFcmTries()) {
                $delivery->status = PushDeliveryStatus::Failed;
                $delivery->failed_at = now();
                $delivery->save();

                Log::warning('push.delivery_retries_exhausted', [
                    'push_delivery_id' => $delivery->id,
                    'notification_id' => $delivery->notification_id,
                    'push_device_id' => $delivery->push_device_id,
                    'category' => $result->errorCategory?->value,
                    'http_status' => $result->httpStatus,
                    'attempts' => $delivery->attempts,
                ]);

                return ['outcome' => 'failed', 'fcm_attempts' => $delivery->attempts];
            }

            $delivery->status = PushDeliveryStatus::Pending;
            $delivery->save();

            return [
                'outcome' => 'retry',
                'retry_after_seconds' => $result->retryAfterSeconds,
                'fcm_attempts' => $delivery->attempts,
            ];
        }

        $delivery->status = PushDeliveryStatus::Failed;
        $delivery->failed_at = now();
        $delivery->save();

        Log::warning('push.delivery_failed', [
            'push_delivery_id' => $delivery->id,
            'notification_id' => $delivery->notification_id,
            'push_device_id' => $delivery->push_device_id,
            'category' => $result->errorCategory?->value ?? FcmErrorCategory::Unknown->value,
            'http_status' => $result->httpStatus,
            'attempts' => $delivery->attempts,
        ]);

        return ['outcome' => 'failed', 'fcm_attempts' => $delivery->attempts];
    }

    /**
     * Backoff for provider retries. Uses FCM attempt count (not Laravel job attempts).
     * Retry-After (when present) is honored with a floor of 60s (quota / 429 safe).
     */
    public function retryDelaySeconds(int $fcmAttempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return max(60, $retryAfterSeconds);
        }

        /** @var list<int> $backoff */
        $backoff = config('firebase.push.backoff', [60, 120, 300, 900]);
        $index = max(0, min(count($backoff) - 1, $fcmAttempt - 1));

        return (int) ($backoff[$index] ?? 60);
    }

    public function maxFcmTries(): int
    {
        return max(1, (int) config('firebase.push.tries', 5));
    }

    /** @deprecated Use maxFcmTries() */
    public function maxTries(): int
    {
        return $this->maxFcmTries();
    }

    public function pushEnabled(): bool
    {
        return (bool) config('firebase.push.enabled', false);
    }

    public function queueName(): string
    {
        return (string) config('firebase.push.queue', 'push');
    }

    private function dispatchJobSafely(int $pushDeliveryId): void
    {
        try {
            SendPushNotificationJob::dispatch($pushDeliveryId)
                ->onQueue($this->queueName());
        } catch (Throwable $e) {
            // Delivery row stays pending for push:dispatch-pending recovery.
            Log::error('push.dispatch_failed', [
                'push_delivery_id' => $pushDeliveryId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{outcome: string, delivery?: PushDelivery, notification?: Notification, token?: string, token_hash?: string}
     */
    private function claimDelivery(int $pushDeliveryId): array
    {
        return DB::transaction(function () use ($pushDeliveryId) {
            $delivery = $this->deliveries->findById($pushDeliveryId, lock: true);
            if ($delivery === null) {
                return ['outcome' => 'skipped'];
            }

            if ($delivery->status->isTerminal()) {
                return ['outcome' => 'skipped'];
            }

            $notification = Notification::query()->find($delivery->notification_id);
            if ($notification === null) {
                $delivery->status = PushDeliveryStatus::Failed;
                $delivery->failed_at = now();
                $delivery->last_error_category = 'NOTIFICATION_MISSING';
                $delivery->last_attempt_at = now();
                $delivery->save();

                return ['outcome' => 'skipped'];
            }

            if ($delivery->push_device_id === null) {
                $delivery->status = PushDeliveryStatus::InvalidToken;
                $delivery->failed_at = now();
                $delivery->last_error_category = 'DEVICE_MISSING';
                $delivery->last_attempt_at = now();
                $delivery->save();

                return ['outcome' => 'skipped'];
            }

            /** @var PushDevice|null $device */
            $device = $this->devices->findById((int) $delivery->push_device_id, lock: true);
            if ($device === null) {
                $delivery->status = PushDeliveryStatus::InvalidToken;
                $delivery->push_device_id = null;
                $delivery->failed_at = now();
                $delivery->last_error_category = 'DEVICE_DELETED';
                $delivery->last_attempt_at = now();
                $delivery->save();

                return ['outcome' => 'skipped'];
            }

            $token = (string) $device->token;
            $tokenHash = (string) $device->token_hash;
            if ($token === '' || $tokenHash === '') {
                $delivery->status = PushDeliveryStatus::Failed;
                $delivery->failed_at = now();
                $delivery->last_error_category = 'TOKEN_UNAVAILABLE';
                $delivery->last_attempt_at = now();
                $delivery->save();

                return ['outcome' => 'skipped'];
            }

            // Mark processing for lease; do not increment FCM attempts until send.
            $delivery->status = PushDeliveryStatus::Processing;
            $delivery->last_attempt_at = now();
            $delivery->save();

            return [
                'outcome' => 'claimed',
                'delivery' => $delivery->fresh(),
                'notification' => $notification,
                'token' => $token,
                'token_hash' => $tokenHash,
            ];
        });
    }
}
