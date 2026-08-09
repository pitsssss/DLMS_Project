<?php

namespace App\Jobs;

use App\Enums\PushDeliveryStatus;
use App\Models\PushDelivery;
use App\Modules\Push\Services\PushDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one push_deliveries row via FCM. Job payload is the delivery ID only.
 *
 * Laravel `$tries` / releases are separate from FCM attempts on push_deliveries.attempts.
 */
class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Laravel queue execution budget (releases, overlap noise, timeouts).
     * Actual FCM send budget lives on push_deliveries.attempts + config firebase.push.tries.
     */
    public int $tries;

    /**
     * Soft timeout for this job (seconds). Must be &lt; queue retry_after.
     */
    public int $timeout;

    /**
     * @var list<int>
     */
    public array $backoff;

    public function __construct(
        public readonly int $pushDeliveryId,
    ) {
        $this->tries = max(1, (int) config('firebase.push.job_max_tries', 25));
        $this->timeout = max(30, (int) config('firebase.push.job_timeout_seconds', 60));
        /** @var list<int> $backoff */
        $backoff = config('firebase.push.backoff', [60, 120, 300, 900]);
        $this->backoff = $backoff;
        $this->onQueue((string) config('firebase.push.queue', 'push'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $expire = max(
            $this->timeout + 30,
            (int) config('firebase.push.processing_lease_seconds', 180),
        );

        // dontRelease: overlapping duplicate is discarded — does not burn FCM retry budget.
        return [
            (new WithoutOverlapping('push-delivery:'.$this->pushDeliveryId))
                ->dontRelease()
                ->expireAfter($expire),
        ];
    }

    public function handle(PushDeliveryService $push): void
    {
        if (! $push->pushEnabled()) {
            return;
        }

        $result = $push->processDelivery($this->pushDeliveryId);

        if (($result['outcome'] ?? null) === 'retry') {
            $fcmAttempts = (int) ($result['fcm_attempts'] ?? 1);
            $delay = $push->retryDelaySeconds(
                $fcmAttempts,
                $result['retry_after_seconds'] ?? null,
            );
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $delivery = PushDelivery::query()->find($this->pushDeliveryId);
            if ($delivery !== null && ! $delivery->status->isTerminal()) {
                $delivery->status = PushDeliveryStatus::Failed;
                $delivery->failed_at = now();
                $delivery->last_error_category = $delivery->last_error_category ?: 'RETRIES_EXHAUSTED';
                $delivery->last_attempt_at = now();
                $delivery->save();
            }
        } catch (Throwable) {
            // Isolation: never throw from failed().
        }

        Log::error('push.job_failed', [
            'push_delivery_id' => $this->pushDeliveryId,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
