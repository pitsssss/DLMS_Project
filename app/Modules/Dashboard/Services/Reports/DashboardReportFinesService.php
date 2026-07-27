<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\FineStatus;
use App\Models\Fine;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Dashboard\Support\Reports\ReportPeriodResolver;
use App\Modules\Dashboard\Support\Reports\ReportResponse;
use App\Modules\Dashboard\Support\Reports\ReportSeriesBuilder;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardReportFinesService
{
    public function __construct(
        private readonly ReportPeriodResolver $periods,
        private readonly BusinessClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $context = $this->periods->resolve($filters);
        $base = $this->filteredQuery($context, $filters);

        $total = (clone $base)->count();
        $paid = (clone $base)->where('status', FineStatus::Paid)->count();
        $unpaid = (clone $base)->where('status', FineStatus::Unpaid)->count();
        $cancelled = (clone $base)->where('status', FineStatus::Cancelled)->count();

        $paidAmount = DashboardPaymentPresenter::money(
            (clone $base)->where('status', FineStatus::Paid)->sum('amount')
        );
        $unpaidAmount = DashboardPaymentPresenter::money(
            (clone $base)->where('status', FineStatus::Unpaid)->sum('amount')
        );

        $bucketExpr = $this->periods->bucketExpression('created_at', $context['group_by']);
        $createdRows = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();

        $paidBucketExpr = $this->periods->bucketExpression('paid_at', $context['group_by']);
        $paidRows = (clone $base)
            ->where('status', FineStatus::Paid)
            ->whereNotNull('paid_at')
            ->selectRaw("{$paidBucketExpr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) as aggregate_count'), DB::raw('COALESCE(SUM(amount), 0) as total_amount'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status instanceof FineStatus ? $row->status->value : (string) $row->status,
                'count' => (int) $row->aggregate_count,
                'amount' => DashboardPaymentPresenter::money($row->total_amount),
            ])
            ->values()
            ->all();

        $byReason = (clone $base)
            ->select('reason', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('reason')
            ->orderByDesc('aggregate_count')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'reason' => (string) $row->reason,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = (clone $base)
            ->with(['citizen:id,name', 'license:id,license_number'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function (Fine $fine) {
            return [
                'id' => $fine->id,
                'citizen' => $fine->citizen
                    ? ['id' => $fine->citizen->id, 'name' => $fine->citizen->name]
                    : null,
                'license_number' => $fine->license?->license_number,
                'amount' => DashboardPaymentPresenter::money($fine->amount),
                'status' => $fine->status->value,
                'created_at' => $fine->created_at?->toIso8601String(),
                'paid_at' => $fine->paid_at?->toIso8601String(),
            ];
        })->values()->all();

        return ReportResponse::build($context, [
            'summary' => [
                'total' => $total,
                'paid' => $paid,
                'unpaid' => $unpaid,
                'cancelled' => $cancelled,
                'paid_amount' => $paidAmount,
                'unpaid_amount' => $unpaidAmount,
                'currency' => null,
                'currency_note' => 'Fines have no currency column; amounts are domain totals without multi-currency grouping.',
            ],
            'series' => [
                ['key' => 'created', 'items' => ReportSeriesBuilder::fill($context, $createdRows, 'count')],
                ['key' => 'paid', 'items' => ReportSeriesBuilder::fill($context, $paidRows, 'count')],
            ],
            'breakdowns' => [
                'by_status' => $byStatus,
                'by_violation_type' => $byReason,
            ],
            'rows' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(array $context, array $filters): Builder
    {
        $query = Fine::query();
        $this->clock->applyUtcRange($query, 'created_at', $context['query_from'], $context['query_to_exclusive']);

        if (! empty($filters['fine_status'])) {
            $query->where('status', $filters['fine_status']);
        }

        return $query;
    }
}
