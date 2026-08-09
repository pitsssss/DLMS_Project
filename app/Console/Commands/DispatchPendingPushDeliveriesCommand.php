<?php

namespace App\Console\Commands;

use App\Modules\Push\Services\PushDeliveryService;
use Illuminate\Console\Command;

/**
 * Idempotent recovery for stranded pending push deliveries.
 */
class DispatchPendingPushDeliveriesCommand extends Command
{
    protected $signature = 'push:dispatch-pending
                            {--limit= : Max pending deliveries to dispatch}';

    protected $description = 'Dispatch pending push deliveries that may not be queued';

    public function handle(PushDeliveryService $push): int
    {
        if (! $push->pushEnabled()) {
            $this->warn('Firebase push is disabled (FIREBASE_PUSH_ENABLED=false).');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: config('firebase.push.recovery_batch_size', 100));
        $result = $push->dispatchPending($limit);

        $this->info(
            "Dispatched={$result['dispatched']} skipped={$result['skipped']} reclaimed={$result['reclaimed']}"
        );

        return self::SUCCESS;
    }
}
