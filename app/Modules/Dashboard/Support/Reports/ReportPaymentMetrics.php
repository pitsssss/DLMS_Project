<?php

namespace App\Modules\Dashboard\Support\Reports;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Payments\Support\Money;
use Illuminate\Database\Eloquent\Builder;

final class ReportPaymentMetrics
{
    /**
     * @return array{
     *     completed_count: int,
     *     completed_amount_by_currency: array<string, string>|null,
     *     completed_amount: ?string,
     *     currency: ?string,
     *     pending_count: int,
     *     failed_count: int,
     *     under_verification_count: int
     * }
     */
    public static function applicationPayments(?callable $range = null): array
    {
        $base = Payment::query()->whereNull('fine_id');
        if ($range !== null) {
            $range($base);
        }

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof PaymentStatus ? $row->status->value : (string) $row->status);

        $count = fn (PaymentStatus $s) => (int) ($byStatus[$s->value]->cnt ?? 0);

        $currencies = (clone $base)->where('status', PaymentStatus::Completed)->distinct()->pluck('currency')->filter()->values();
        $byCurrency = null;
        $singleAmount = null;
        $singleCurrency = null;

        if ($currencies->count() === 1) {
            $singleCurrency = (string) $currencies->first();
            $singleAmount = DashboardPaymentPresenter::money($byStatus[PaymentStatus::Completed->value]->total_amount ?? 0);
        } elseif ($currencies->count() > 1) {
            $byCurrency = [];
            foreach ($currencies as $currency) {
                $total = (clone $base)
                    ->where('status', PaymentStatus::Completed)
                    ->where('currency', $currency)
                    ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
                    ->value('total_amount');
                $byCurrency[$currency] = DashboardPaymentPresenter::money($total ?? 0);
            }
        } else {
            $singleAmount = '0.00';
            $singleCurrency = null;
        }

        return [
            'completed_count' => $count(PaymentStatus::Completed),
            'completed_amount' => $singleAmount,
            'completed_amount_by_currency' => $byCurrency,
            'currency' => $singleCurrency,
            'pending_count' => $count(PaymentStatus::Pending),
            'failed_count' => $count(PaymentStatus::Failed),
            'under_verification_count' => $count(PaymentStatus::UnderVerification),
        ];
    }

    /**
     * @return array{count: int, amount: string}
     */
    public static function dueFees(): array
    {
        $feeMap = self::preloadFeeMap();
        $completed = Payment::query()
            ->whereNull('fine_id')
            ->where('status', PaymentStatus::Completed)
            ->whereIn('application_id', function ($sub): void {
                $sub->select('id')
                    ->from('license_applications')
                    ->where('status', ApplicationStatus::PaymentPending->value)
                    ->whereNull('deleted_at');
            })
            ->get(['application_id', 'fee_id'])
            ->map(fn (Payment $p) => Payment::obligationKey((int) $p->application_id, (int) $p->fee_id))
            ->all();

        $count = 0;
        $sum = '0.00';

        foreach (LicenseApplication::query()
            ->where('status', ApplicationStatus::PaymentPending)
            ->with('serviceType:id,code')
            ->select(['id', 'service_type_id', 'license_type_id'])
            ->lazy(100) as $application) {
            $code = ServiceWorkflow::feeCode($application->serviceType?->code);
            $fee = self::resolveFeeFromMap($feeMap, $application, $code);
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

        return ['count' => $count, 'amount' => Money::format($sum)];
    }

    public static function applyRange(Builder $query, string $column, array $context): void
    {
        $query->where($column, '>=', $context['query_from'])
            ->where($column, '<', $context['query_to_exclusive']);
    }

    /**
     * @return array<string, Fee>
     */
    private static function preloadFeeMap(): array
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
    private static function resolveFeeFromMap(array $map, LicenseApplication $application, string $code): ?Fee
    {
        $specific = $map[$code.'|'.$application->license_type_id.'|'.$application->service_type_id] ?? null;
        if ($specific) {
            return $specific;
        }

        return $map[$code.'|null|'.$application->service_type_id] ?? null;
    }
}
