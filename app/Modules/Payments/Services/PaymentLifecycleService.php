<?php

namespace App\Modules\Payments\Services;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentFailureCode;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Fine;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Support\NotificationEventKey;
use App\Modules\Payments\Support\Money;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

class PaymentLifecycleService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $mergeMetadata
     */
    public function completeVerifiedPayment(
        int $paymentId,
        ?User $actor,
        array $mergeMetadata = [],
        ?string $providerReference = null,
        string $source = 'system'
    ): Payment {
        $result = DB::transaction(function () use ($paymentId, $actor, $mergeMetadata, $providerReference, $source) {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Completed) {
                return [
                    'payment' => $payment->fresh(['fee', 'application', 'fine']),
                    'transitioned' => false,
                    'notified' => false,
                    'notify_fine_paid' => false,
                ];
            }

            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::UnderVerification], true)) {
                throw new ApiException('messages.payments.cannot_complete_state', 422);
            }

            if ($payment->isApplicationPayment()) {
                return $this->completeApplicationPaymentLocked($payment, $actor, $mergeMetadata, $providerReference, $source);
            }

            if ($payment->isFinePayment()) {
                return $this->completeFinePaymentLocked($payment, $actor, $mergeMetadata, $providerReference, $source);
            }

            throw new ApiException('messages.payments.not_found', 404);
        });

        if ($result['notified'] ?? false) {
            $this->notifyCompleted($result['payment']);
        }

        if ($result['notify_fine_paid'] ?? false) {
            $this->notifyFinePaid($result['payment']);
        }

        if ($result['notify_under_verification'] ?? false) {
            $this->notifyUnderVerification($result['payment']);
        }

        return $result['payment'];
    }

    /**
     * @param  array<string, mixed>  $mergeMetadata
     * @return array<string, mixed>
     */
    private function completeApplicationPaymentLocked(
        Payment $payment,
        ?User $actor,
        array $mergeMetadata,
        ?string $providerReference,
        string $source
    ): array {
        $obligationKey = Payment::obligationKey((int) $payment->application_id, (int) $payment->fee_id);

        $alreadySettled = Payment::query()
            ->where('settled_obligation_key', $obligationKey)
            ->where('id', '!=', $payment->id)
            ->lockForUpdate()
            ->exists();

        if ($alreadySettled) {
            $changed = $this->markUnderVerificationLocked(
                $payment,
                PaymentFailureCode::ObligationAlreadySettled,
                $mergeMetadata,
                $actor,
                $source
            );

            return [
                'payment' => $payment->fresh(['fee', 'application', 'fine']),
                'transitioned' => false,
                'notified' => false,
                'notify_fine_paid' => false,
                'notify_under_verification' => $changed,
            ];
        }

        $application = LicenseApplication::query()
            ->whereKey($payment->application_id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->markPaymentCompleted($payment, $obligationKey, $mergeMetadata, $providerReference, $actor, $source, [
            'application_id' => $payment->application_id,
        ]);

        $transitioned = false;
        if ($application->status === ApplicationStatus::PaymentPending) {
            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::PaymentCompleted,
                $actor,
                __('messages.payments.note_fee_completed')
            );

            $application = $application->fresh();
            if ($application === null) {
                throw new ApiException('messages.applications.not_found', 404);
            }

            $application->loadMissing('serviceType');
            $nextStatus = ServiceWorkflow::requiresTests($application->serviceType?->code)
                ? ApplicationStatus::AppointmentPending
                : ApplicationStatus::Approved;

            $this->applications->transitionStatus(
                $application,
                $nextStatus,
                $actor,
                $nextStatus === ApplicationStatus::Approved
                    ? __('messages.payments.note_ready_for_issuance')
                    : __('messages.payments.note_payment_cleared')
            );
            $transitioned = true;
        }

        return [
            'payment' => $payment->fresh(['fee', 'application', 'fine']),
            'transitioned' => $transitioned,
            'notified' => true,
            'notify_fine_paid' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $mergeMetadata
     * @return array<string, mixed>
     */
    private function completeFinePaymentLocked(
        Payment $payment,
        ?User $actor,
        array $mergeMetadata,
        ?string $providerReference,
        string $source
    ): array {
        $obligationKey = Payment::fineObligationKey((int) $payment->fine_id);

        $alreadySettled = Payment::query()
            ->where('settled_obligation_key', $obligationKey)
            ->where('id', '!=', $payment->id)
            ->lockForUpdate()
            ->exists();

        if ($alreadySettled) {
            $changed = $this->markUnderVerificationLocked(
                $payment,
                PaymentFailureCode::ObligationAlreadySettled,
                $mergeMetadata,
                $actor,
                $source
            );

            return [
                'payment' => $payment->fresh(['fee', 'application', 'fine']),
                'transitioned' => false,
                'notified' => false,
                'notify_fine_paid' => false,
                'notify_under_verification' => $changed,
            ];
        }

        $fine = Fine::query()
            ->whereKey($payment->fine_id)
            ->lockForUpdate()
            ->firstOrFail();

        // Race: employee cancelled fine while Stripe checkout was in flight.
        if ($fine->status === FineStatus::Cancelled) {
            $changed = $this->markUnderVerificationLocked(
                $payment,
                PaymentFailureCode::WorkflowConflict,
                array_merge($mergeMetadata, [
                    'conflict' => 'fine_cancelled_before_settlement',
                    'fine_id' => $fine->id,
                    'fine_status' => $fine->status->value,
                ]),
                $actor,
                $source
            );

            return [
                'payment' => $payment->fresh(['fee', 'application', 'fine']),
                'transitioned' => false,
                'notified' => false,
                'notify_fine_paid' => false,
                'notify_under_verification' => $changed,
            ];
        }

        // Race: employee manually marked fine paid before webhook.
        // Keep provider-confirmed payment as completed financial evidence without
        // re-transitioning Fine or emitting a second FinePaid notification.
        $fineAlreadyPaid = $fine->status === FineStatus::Paid;

        $this->markPaymentCompleted($payment, $obligationKey, $mergeMetadata, $providerReference, $actor, $source, [
            'fine_id' => $payment->fine_id,
            'fine_already_paid' => $fineAlreadyPaid,
        ]);

        $notifyFinePaid = false;
        if (! $fineAlreadyPaid) {
            $oldFineStatus = $fine->status->value;
            $fine->status = FineStatus::Paid;
            $fine->paid_at = now();
            $fine->save();

            $this->auditLogs->log(
                $actor,
                'fine.updated',
                'fine',
                $fine->id,
                ['status' => $oldFineStatus],
                [
                    'status' => FineStatus::Paid->value,
                    'paid_at' => $fine->paid_at?->toIso8601String(),
                    'payment_id' => $payment->id,
                    'source' => $source,
                    'settlement' => 'electronic',
                ]
            );

            $notifyFinePaid = true;
        }

        return [
            'payment' => $payment->fresh(['fee', 'application', 'fine']),
            'transitioned' => $notifyFinePaid,
            'notified' => false,
            'notify_fine_paid' => $notifyFinePaid,
        ];
    }

    /**
     * @param  array<string, mixed>  $mergeMetadata
     * @param  array<string, mixed>  $auditExtra
     */
    private function markPaymentCompleted(
        Payment $payment,
        string $obligationKey,
        array $mergeMetadata,
        ?string $providerReference,
        ?User $actor,
        string $source,
        array $auditExtra = []
    ): void {
        $oldStatus = $payment->status->value;

        $payment->status = PaymentStatus::Completed;
        $payment->paid_at = now();
        $payment->failed_at = null;
        $payment->failure_code = null;
        $payment->failure_message = null;
        $payment->active_obligation_key = null;
        $payment->settled_obligation_key = $obligationKey;
        $payment->last_verified_at = now();

        if ($providerReference !== null && $providerReference !== '') {
            $payment->provider_reference = $providerReference;
        }

        $payment->metadata = array_merge($payment->metadata ?? [], $mergeMetadata);
        $payment->save();

        $this->auditLogs->log(
            $actor,
            'payment.completed',
            'payment',
            $payment->id,
            [
                'status' => $oldStatus,
                'amount' => Money::format((string) $payment->amount),
                'currency' => $payment->currency,
            ],
            array_merge([
                'status' => PaymentStatus::Completed->value,
                'amount' => Money::format((string) $payment->amount),
                'currency' => $payment->currency,
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'source' => $source,
                'payment_number' => $payment->payment_number,
                'application_id' => $payment->application_id,
                'fine_id' => $payment->fine_id,
            ], $auditExtra)
        );
    }

    /**
     * @param  array<string, mixed>  $mergeMetadata
     */
    public function markFailed(
        int $paymentId,
        PaymentFailureCode $code,
        array $mergeMetadata = [],
        ?User $actor = null,
        string $source = 'system'
    ): Payment {
        $result = DB::transaction(function () use ($paymentId, $code, $mergeMetadata, $actor, $source) {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Completed) {
                return ['payment' => $payment->fresh(['fee', 'application', 'fine']), 'notify_failed' => false];
            }

            if ($payment->status === PaymentStatus::Failed
                && $payment->failure_code === $code->value) {
                return ['payment' => $payment->fresh(['fee', 'application', 'fine']), 'notify_failed' => false];
            }

            $oldStatus = $payment->status->value;
            $payment->status = PaymentStatus::Failed;
            $payment->failure_code = $code->value;
            $payment->failure_message = $code->label();
            $payment->failed_at = now();
            $payment->active_obligation_key = null;
            $payment->metadata = array_merge($payment->metadata ?? [], $mergeMetadata);
            $payment->save();

            $this->auditLogs->log(
                $actor,
                'payment.failed',
                'payment',
                $payment->id,
                ['status' => $oldStatus],
                [
                    'status' => PaymentStatus::Failed->value,
                    'failure_code' => $code->value,
                    'source' => $source,
                    'payment_number' => $payment->payment_number,
                    'application_id' => $payment->application_id,
                    'fine_id' => $payment->fine_id,
                ]
            );

            return ['payment' => $payment->fresh(['fee', 'application', 'fine']), 'notify_failed' => true];
        });

        if ($result['notify_failed']) {
            $this->notifyFailed($result['payment']);
        }

        return $result['payment'];
    }

    /**
     * Retire an active attempt when its provider no longer matches the configured provider.
     * Provider identity on a Payment is immutable — never rewrite mock ↔ stripe on the same row.
     *
     * @return bool true when the payment was retired (caller should create a fresh attempt)
     */
    public function retireActiveIfProviderMismatch(
        Payment $payment,
        string $configuredProvider,
        ?User $actor = null,
        string $source = 'citizen'
    ): bool {
        if ((string) $payment->provider === $configuredProvider) {
            return false;
        }

        $this->markFailed(
            $payment->id,
            PaymentFailureCode::ProviderMismatch,
            [
                'previous_provider' => (string) $payment->provider,
                'configured_provider' => $configuredProvider,
                'reason' => 'provider_mismatch_on_reuse',
            ],
            $actor,
            $source
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $mergeMetadata
     */
    public function markUnderVerification(
        int $paymentId,
        PaymentFailureCode $code,
        array $mergeMetadata = [],
        ?User $actor = null,
        string $source = 'system'
    ): Payment {
        $result = DB::transaction(function () use ($paymentId, $code, $mergeMetadata, $actor, $source) {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Completed) {
                return ['payment' => $payment->fresh(['fee', 'application', 'fine']), 'notify' => false];
            }

            $changed = $this->markUnderVerificationLocked($payment, $code, $mergeMetadata, $actor, $source);

            return ['payment' => $payment->fresh(['fee', 'application', 'fine']), 'notify' => $changed];
        });

        if ($result['notify']) {
            $this->notifyUnderVerification($result['payment']);
        }

        return $result['payment'];
    }

    /**
     * @param  array<string, mixed>  $mergeMetadata
     */
    private function markUnderVerificationLocked(
        Payment $payment,
        PaymentFailureCode $code,
        array $mergeMetadata,
        ?User $actor,
        string $source
    ): bool {
        if ($payment->status === PaymentStatus::UnderVerification
            && $payment->failure_code === $code->value) {
            return false;
        }

        $oldStatus = $payment->status->value;
        $payment->status = PaymentStatus::UnderVerification;
        $payment->failure_code = $code->value;
        $payment->failure_message = $code->label();
        $payment->active_obligation_key = $payment->obligationKeyValue();
        $payment->settled_obligation_key = null;
        $payment->metadata = array_merge($payment->metadata ?? [], $mergeMetadata);
        $payment->save();

        $this->auditLogs->log(
            $actor,
            'payment.under_verification',
            'payment',
            $payment->id,
            ['status' => $oldStatus],
            [
                'status' => PaymentStatus::UnderVerification->value,
                'failure_code' => $code->value,
                'source' => $source,
                'payment_number' => $payment->payment_number,
                'application_id' => $payment->application_id,
                'fine_id' => $payment->fine_id,
            ]
        );

        return true;
    }

    private function notifyCompleted(Payment $payment): void
    {
        $payment->loadMissing('application');

        $this->notifications->notify(
            (int) $payment->user_id,
            NotificationType::PaymentCompleted,
            [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'application_id' => $payment->application_id,
                'amount' => Money::format((string) $payment->amount),
                'currency' => $payment->currency,
            ],
            [
                'payment_number' => $payment->payment_number,
                'application_number' => $payment->application?->application_number ?? '',
                'amount' => Money::format((string) $payment->amount),
                'currency' => $payment->currency,
            ],
            NotificationEventKey::forPayment(NotificationType::PaymentCompleted, $payment->id)
        );
    }

    private function notifyFinePaid(Payment $payment): void
    {
        $this->notifications->notify(
            (int) $payment->user_id,
            NotificationType::FinePaid,
            ['fine_id' => $payment->fine_id],
            [],
            NotificationEventKey::forFine(NotificationType::FinePaid, (int) $payment->fine_id)
        );
    }

    private function notifyFailed(Payment $payment): void
    {
        $payment->loadMissing('application');

        $data = [
            'payment_id' => $payment->id,
            'payment_number' => $payment->payment_number,
        ];
        if ($payment->application_id !== null) {
            $data['application_id'] = $payment->application_id;
        }
        if ($payment->fine_id !== null) {
            $data['fine_id'] = $payment->fine_id;
        }

        $this->notifications->notify(
            (int) $payment->user_id,
            NotificationType::PaymentFailed,
            $data,
            [
                'application_number' => $payment->application?->application_number ?? '',
            ],
            NotificationEventKey::forPaymentCode(
                NotificationType::PaymentFailed,
                $payment->id,
                (string) $payment->failure_code
            )
        );
    }

    private function notifyUnderVerification(Payment $payment): void
    {
        $payment->loadMissing('application');

        $data = [
            'payment_id' => $payment->id,
            'payment_number' => $payment->payment_number,
        ];
        if ($payment->application_id !== null) {
            $data['application_id'] = $payment->application_id;
        }
        if ($payment->fine_id !== null) {
            $data['fine_id'] = $payment->fine_id;
        }

        $this->notifications->notify(
            (int) $payment->user_id,
            NotificationType::PaymentUnderVerification,
            $data,
            [
                'application_number' => $payment->application?->application_number ?? '',
            ],
            NotificationEventKey::forPaymentCode(
                NotificationType::PaymentUnderVerification,
                $payment->id,
                (string) $payment->failure_code
            )
        );
    }
}
