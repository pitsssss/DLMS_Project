<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Payments\Services\PaymentReconciliationService;
use App\Modules\Payments\Support\Money;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardPaymentService
{
    public function __construct(
        private readonly PaymentReconciliationService $reconciliation,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->applicationPaymentsQuery()
            ->with([
                'user:id,name',
                'fee:id,code,name',
                'application:id,application_number,status,service_type_id,license_type_id,citizen_id',
                'application.serviceType:id,code,name',
                'application.licenseType:id,code,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyListFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(array $filters = []): array
    {
        $base = $this->applicationPaymentsQuery();
        $this->applyStatsFilters($base, $filters);

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof PaymentStatus ? $row->status->value : (string) $row->status);

        $count = fn (PaymentStatus $s) => (int) ($byStatus[$s->value]->cnt ?? 0);
        $amount = fn (PaymentStatus $s) => DashboardPaymentPresenter::money($byStatus[$s->value]->total_amount ?? 0);

        $tz = (string) config('dlms.business_timezone', 'Asia/Damascus');
        $todayStart = Carbon::now($tz)->startOfDay()->utc();
        $todayEnd = Carbon::now($tz)->endOfDay()->utc();

        $todayCompleted = (clone $base)
            ->where('status', PaymentStatus::Completed)
            ->whereBetween('paid_at', [$todayStart, $todayEnd])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total_amount')
            ->first();

        $currencies = (clone $base)->distinct()->pluck('currency')->filter()->values();
        $byCurrency = [];
        if ($currencies->count() > 1) {
            foreach ($currencies as $currency) {
                $completed = (clone $base)
                    ->where('currency', $currency)
                    ->where('status', PaymentStatus::Completed)
                    ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
                    ->value('total_amount');
                $byCurrency[$currency] = DashboardPaymentPresenter::money($completed ?? 0);
            }
        }

        $due = $this->dueObligationsAggregate();

        return [
            'total_operations' => (clone $base)->count(),
            'completed_operations' => $count(PaymentStatus::Completed),
            'pending_operations' => $count(PaymentStatus::Pending),
            'failed_operations' => $count(PaymentStatus::Failed),
            'under_verification_operations' => $count(PaymentStatus::UnderVerification),
            'completed_amount' => $amount(PaymentStatus::Completed),
            'pending_amount' => $amount(PaymentStatus::Pending),
            'failed_amount' => $amount(PaymentStatus::Failed),
            'under_verification_amount' => $amount(PaymentStatus::UnderVerification),
            'today_completed_operations' => (int) ($todayCompleted->cnt ?? 0),
            'today_completed_amount' => DashboardPaymentPresenter::money($todayCompleted->total_amount ?? 0),
            'due_obligations' => $due['count'],
            'due_amount' => $due['amount'],
            'completed_amount_by_currency' => $byCurrency ?: null,
            'currency' => $currencies->count() === 1 ? $currencies->first() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $statuses = array_map(
            fn (PaymentStatus $s) => [
                'value' => $s->value,
                'label' => __('messages.payments.statuses.'.$s->value),
            ],
            PaymentStatus::cases()
        );

        $providers = [
            ['value' => 'mock', 'label' => __('messages.payments.providers.mock')],
            ['value' => 'stripe', 'label' => __('messages.payments.providers.stripe')],
        ];

        $currencies = Payment::query()
            ->whereNull('fine_id')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->filter()
            ->values()
            ->map(fn ($c) => ['value' => $c, 'label' => $c === 'SYP' ? 'ليرة سورية' : $c])
            ->all();

        if ($currencies === []) {
            $currencies = [['value' => 'SYP', 'label' => 'ليرة سورية']];
        }

        $feeTypes = Fee::query()
            ->whereIn('code', [
                'application_fee',
                'renewal_fee',
                'lost_replacement_fee',
                'damaged_replacement_fee',
                'unblock_fee',
            ])
            ->select('code', 'name')
            ->distinct()
            ->orderBy('code')
            ->get()
            ->map(fn (Fee $fee) => ['value' => $fee->code, 'label' => $fee->name])
            ->values()
            ->all();

        return [
            'statuses' => $statuses,
            'providers' => $providers,
            'currencies' => $currencies,
            'fee_types' => $feeTypes,
        ];
    }

    public function paginateDueFees(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = LicenseApplication::query()
            ->where('status', ApplicationStatus::PaymentPending)
            ->with(['citizen:id,name', 'serviceType:id,code,name', 'licenseType:id,code,name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function (Builder $q) use ($term): void {
                $q->where('application_number', 'LIKE', '%'.$term.'%')
                    ->orWhereHas('citizen', fn (Builder $c) => $c->where('name', 'LIKE', '%'.$term.'%'));
            });
        }

        if (! empty($filters['service_type_code'])) {
            $query->whereHas('serviceType', fn (Builder $q) => $q->where('code', $filters['service_type_code']));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('updated_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('updated_at', '<=', $filters['date_to']);
        }

        $paginator = $query->paginate($perPage);

        $feeMap = $this->preloadFeeMap();
        $applicationIds = collect($paginator->items())->pluck('id')->all();

        $completedKeys = Payment::query()
            ->whereNull('fine_id')
            ->where('status', PaymentStatus::Completed)
            ->whereIn('application_id', $applicationIds)
            ->get(['application_id', 'fee_id'])
            ->map(fn (Payment $p) => Payment::obligationKey((int) $p->application_id, (int) $p->fee_id))
            ->all();

        $attempts = Payment::query()
            ->whereNull('fine_id')
            ->whereIn('application_id', $applicationIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('application_id');

        $items = [];
        foreach ($paginator->items() as $application) {
            $code = ServiceWorkflow::feeCode($application->serviceType?->code);
            $fee = $this->resolveFeeFromMap($feeMap, $application, $code);

            if ($fee === null) {
                continue;
            }

            if (! empty($filters['fee_code']) && $fee->code !== $filters['fee_code']) {
                continue;
            }

            $key = Payment::obligationKey($application->id, $fee->id);
            if (in_array($key, $completedKeys, true)) {
                continue;
            }

            $appAttempts = $attempts->get($application->id, collect())
                ->where('fee_id', $fee->id)
                ->values();

            $hasPending = $appAttempts->contains(
                fn (Payment $p) => in_array($p->status, [PaymentStatus::Pending, PaymentStatus::UnderVerification], true)
            );

            if (array_key_exists('has_pending_attempt', $filters) && $filters['has_pending_attempt'] !== null) {
                $want = filter_var($filters['has_pending_attempt'], FILTER_VALIDATE_BOOLEAN);
                if ($want !== $hasPending) {
                    continue;
                }
            }

            $items[] = [
                'application' => $application,
                'fee' => $fee,
                'attempts_count' => $appAttempts->count(),
                'latest_attempt' => $appAttempts->first(),
            ];
        }

        // Replace page items with enriched due-fee payloads (same pagination meta).
        $paginator->setCollection(collect($items));

        return $paginator;
    }

    public function getPayment(int $paymentId): Payment
    {
        $payment = $this->applicationPaymentsQuery()
            ->whereKey($paymentId)
            ->with([
                'user:id,name',
                'fee',
                'application.serviceType',
                'application.licenseType',
            ])
            ->first();

        if ($payment === null) {
            throw new ApiException('messages.payments.not_found', 404);
        }

        $payment->attempts_count = Payment::query()
            ->whereNull('fine_id')
            ->where('application_id', $payment->application_id)
            ->where('fee_id', $payment->fee_id)
            ->count();
        $payment->syncOriginal();

        return $payment;
    }

    public function paginateAttempts(Payment $payment, int $perPage): LengthAwarePaginator
    {
        return Payment::query()
            ->whereNull('fine_id')
            ->where('application_id', $payment->application_id)
            ->where('fee_id', $payment->fee_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function paginateAuditLogs(Payment $payment, int $perPage): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', 'payment')
            ->where('entity_id', $payment->id)
            ->whereIn('action', [
                'payment.created',
                'payment.initiated',
                'payment.completed',
                'payment.failed',
                'payment.under_verification',
                'payment.verified',
                'payment.reconciled',
            ])
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array{payment: Payment, result: string}
     */
    public function verify(User $actor, int $paymentId): array
    {
        $payment = $this->getPayment($paymentId);

        return $this->reconciliation->reconcile($payment, $actor, 'dashboard');
    }

    private function applicationPaymentsQuery(): Builder
    {
        return Payment::query()->whereNull('fine_id')->whereNotNull('application_id');
    }

    private function applyListFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function (Builder $q) use ($term): void {
                $q->where('payment_number', 'LIKE', '%'.$term.'%')
                    ->orWhere('provider_reference', 'LIKE', '%'.$term.'%')
                    ->orWhereHas('application', fn (Builder $a) => $a->where('application_number', 'LIKE', '%'.$term.'%'))
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'LIKE', '%'.$term.'%'));
            });
        }

        if (! empty($filters['status'])) {
            $status = $filters['status'] instanceof PaymentStatus
                ? $filters['status']->value
                : $filters['status'];
            $query->where('status', $status);
        }

        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }

        if (! empty($filters['service_type_code'])) {
            $query->whereHas('application.serviceType', fn (Builder $q) => $q->where('code', $filters['service_type_code']));
        }

        if (! empty($filters['fee_code'])) {
            $query->whereHas('fee', fn (Builder $q) => $q->where('code', $filters['fee_code']));
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', strtoupper($filters['currency']));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }

    private function applyStatsFilters(Builder $query, array $filters): void
    {
        // Intentionally ignore status so semantic cards remain correct.
        unset($filters['status']);
        $this->applyListFilters($query, $filters);
    }

    /**
     * @return array{count: int, amount: string}
     */
    private function dueObligationsAggregate(): array
    {
        $applications = LicenseApplication::query()
            ->where('status', ApplicationStatus::PaymentPending)
            ->with('serviceType:id,code')
            ->get(['id', 'service_type_id', 'license_type_id']);

        if ($applications->isEmpty()) {
            return ['count' => 0, 'amount' => '0.00'];
        }

        $feeMap = $this->preloadFeeMap();
        $completed = Payment::query()
            ->whereNull('fine_id')
            ->where('status', PaymentStatus::Completed)
            ->whereIn('application_id', $applications->pluck('id'))
            ->get(['application_id', 'fee_id'])
            ->map(fn (Payment $p) => Payment::obligationKey((int) $p->application_id, (int) $p->fee_id))
            ->all();

        $count = 0;
        $sum = '0.00';
        foreach ($applications as $application) {
            $code = ServiceWorkflow::feeCode($application->serviceType?->code);
            $fee = $this->resolveFeeFromMap($feeMap, $application, $code);
            if ($fee === null) {
                continue;
            }
            $key = Payment::obligationKey($application->id, $fee->id);
            if (in_array($key, $completed, true)) {
                continue;
            }
            $count++;
            $sum = Money::sum([$sum, (string) $fee->amount]);
        }

        return ['count' => $count, 'amount' => $sum];
    }

    /**
     * @return array<string, Fee>
     */
    private function preloadFeeMap(): array
    {
        $fees = Fee::query()
            ->where('is_active', true)
            ->whereIn('code', [
                'application_fee',
                'renewal_fee',
                'lost_replacement_fee',
                'damaged_replacement_fee',
                'unblock_fee',
            ])
            ->get();

        $map = [];
        foreach ($fees as $fee) {
            $map[$fee->code.'|'.$fee->license_type_id.'|'.$fee->service_type_id] = $fee;
            if ($fee->license_type_id === null) {
                $map[$fee->code.'|null|'.$fee->service_type_id] = $fee;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, Fee>  $map
     */
    private function resolveFeeFromMap(array $map, LicenseApplication $application, string $code): ?Fee
    {
        $specific = $map[$code.'|'.$application->license_type_id.'|'.$application->service_type_id] ?? null;
        if ($specific) {
            return $specific;
        }

        return $map[$code.'|null|'.$application->service_type_id] ?? null;
    }
}
