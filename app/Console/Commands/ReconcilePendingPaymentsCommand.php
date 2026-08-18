<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Modules\Payments\Services\PaymentReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-pending
                            {--minutes= : Stale threshold in minutes}
                            {--limit= : Max payments to process}';

    protected $description = 'Reconcile stale pending/under_verification Stripe application and fine payments';

    public function handle(PaymentReconciliationService $reconciliation): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('payment.reconciliation.stale_pending_minutes', 60));
        $limit = (int) ($this->option('limit') ?: config('payment.reconciliation.batch_size', 50));

        $query = Payment::query()
            ->where('provider', 'stripe')
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::UnderVerification])
            ->whereNotNull('provider_reference')
            ->where(function ($q): void {
                $q->where(function ($app): void {
                    $app->whereNull('fine_id')->whereNotNull('application_id');
                })->orWhere(function ($fine): void {
                    $fine->whereNotNull('fine_id')->whereNull('application_id');
                });
            })
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit($limit);

        $processed = 0;
        $failed = 0;

        foreach ($query->cursor() as $payment) {
            try {
                $reconciliation->reconcile($payment, null, 'scheduled');
                $processed++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $this->info("Reconciled={$processed} failed={$failed}");

        return self::SUCCESS;
    }
}
