<?php

namespace App\Modules\Payments\Services;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentFailureCode;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Enums\NotificationType;
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
                return ['payment' => $payment->fresh(['fee', 'application']), 'transitioned' => false, 'notified' => false];
            }

            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::UnderVerification], true)) {
                throw new ApiException('messages.payments.cannot_complete_state', 422);
            }

            if (! $payment->isApplicationPayment()) {
                throw new ApiException('messages.payments.not_found', 404);
            }

            $obligationKey = Payment::obligationKey((int) $payment->application_id, (int) $payment->fee_id);

            $alreadySettled = Payment::query()
                ->where('settled_obligation_key', $obligationKey)
                ->where('id', '!=', $payment->id)
                ->lockForUpdate()
                ->exists();

            if ($alreadySettled) {
                $this->markUnderVerificationLocked(
                    $payment,
                    PaymentFailureCode::ObligationAlreadySettled,
                    $mergeMetadata,
                    $actor,
                    $source
                );

                return ['payment' => $payment->fresh(['fee', 'application']), 'transitioned' => false, 'notified' => false];
            }

            $application = LicenseApplication::query()
                ->whereKey($payment->application_id)
                ->lockForUpdate()
                ->firstOrFail();

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
                [
                    'status' => PaymentStatus::Completed->value,
                    'amount' => Money::format((string) $payment->amount),
                    'currency' => $payment->currency,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'source' => $source,
                    'payment_number' => $payment->payment_number,
                    'application_id' => $payment->application_id,
                ]
            );

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
                'payment' => $payment->fresh(['fee', 'application']),
                'transitioned' => $transitioned,
                'notified' => true,
            ];
        });

        if ($result['notified']) {
            $this->notifyCompleted($result['payment']);
        }

        return $result['payment'];
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
        return DB::transaction(function () use ($paymentId, $code, $mergeMetadata, $actor, $source) {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Completed) {
                return $payment->fresh(['fee', 'application']);
            }

            if ($payment->status === PaymentStatus::Failed
                && $payment->failure_code === $code->value) {
                return $payment->fresh(['fee', 'application']);
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
                ]
            );

            return $payment->fresh(['fee', 'application']);
        });
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
        return DB::transaction(function () use ($paymentId, $code, $mergeMetadata, $actor, $source) {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Completed) {
                return $payment->fresh(['fee', 'application']);
            }

            $this->markUnderVerificationLocked($payment, $code, $mergeMetadata, $actor, $source);

            return $payment->fresh(['fee', 'application']);
        });
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
    ): void {
        if ($payment->status === PaymentStatus::UnderVerification
            && $payment->failure_code === $code->value) {
            return;
        }

        $oldStatus = $payment->status->value;
        $payment->status = PaymentStatus::UnderVerification;
        $payment->failure_code = $code->value;
        $payment->failure_message = $code->label();
        $payment->active_obligation_key = Payment::obligationKey(
            (int) $payment->application_id,
            (int) $payment->fee_id
        );
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
            ]
        );
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
}
