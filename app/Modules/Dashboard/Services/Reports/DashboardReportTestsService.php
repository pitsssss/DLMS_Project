<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\TestResultStatus;
use App\Models\TestResult;
use App\Modules\Dashboard\Support\Reports\ReportContract;
use App\Modules\Dashboard\Support\Reports\ReportPeriodResolver;
use App\Modules\Dashboard\Support\Reports\ReportResponse;
use App\Modules\Dashboard\Support\Reports\ReportSeriesBuilder;
use App\Support\BusinessClock;
use App\Support\Msg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardReportTestsService
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
        $passed = (clone $base)->where('result', TestResultStatus::Passed)->count();
        $failed = (clone $base)->where('result', TestResultStatus::Failed)->count();
        $noShow = (clone $base)->where('result', TestResultStatus::NoShow)->count();
        $pending = (clone $base)->where('result', TestResultStatus::Pending)->count();
        $retests = (clone $base)->where('attempt_number', '>', 1)->count();

        $avgAttempts = (clone $base)
            ->where('result', TestResultStatus::Passed)
            ->selectRaw('AVG(attempt_number) as avg_attempts')
            ->value('avg_attempts');

        $bucketExpr = $this->periods->bucketExpression('recorded_at', $context['group_by']);
        $seriesRows = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket, result, COUNT(*) as aggregate_count")
            ->groupBy('bucket', 'result')
            ->get();

        $passedRows = [];
        $failedRows = [];
        $noShowRows = [];
        foreach ($seriesRows as $row) {
            $result = $row->result instanceof TestResultStatus ? $row->result->value : (string) $row->result;
            $bucket = (string) $row->bucket;
            $count = (int) $row->aggregate_count;
            match ($result) {
                TestResultStatus::Passed->value => $passedRows[$bucket] = ($passedRows[$bucket] ?? 0) + $count,
                TestResultStatus::Failed->value => $failedRows[$bucket] = ($failedRows[$bucket] ?? 0) + $count,
                TestResultStatus::NoShow->value => $noShowRows[$bucket] = ($noShowRows[$bucket] ?? 0) + $count,
                default => null,
            };
        }

        $byType = (clone $base)
            ->join('test_types', 'test_types.id', '=', 'test_results.test_type_id')
            ->select('test_types.code', 'test_types.name', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('test_types.code', 'test_types.name')
            ->orderByDesc('aggregate_count')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();

        $byResult = (clone $base)
            ->select('result', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('result')
            ->get()
            ->map(function ($row) {
                $result = $row->result instanceof TestResultStatus ? $row->result->value : (string) $row->result;

                return [
                    'result' => $result,
                    'label' => Msg::get('tests.statuses.'.$result),
                    'count' => (int) $row->aggregate_count,
                ];
            })
            ->values()
            ->all();

        $byAttempt = (clone $base)
            ->select('attempt_number', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('attempt_number')
            ->orderBy('attempt_number')
            ->get()
            ->map(fn ($row) => [
                'attempt_number' => (int) $row->attempt_number,
                'label' => (string) $row->attempt_number,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = (clone $base)
            ->with([
                'application:id,application_number,citizen_id',
                'application.citizen:id,name',
                'testType:id,code,name',
                'recordedBy:id,name',
            ])
            ->orderByDesc('recorded_at')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function (TestResult $result) {
            return [
                'application_number' => $result->application?->application_number,
                'citizen' => $result->application?->citizen
                    ? ['id' => $result->application->citizen->id, 'name' => $result->application->citizen->name]
                    : null,
                'test_type' => $result->testType
                    ? ['code' => $result->testType->code, 'name' => $result->testType->name]
                    : null,
                'attempt_number' => (int) $result->attempt_number,
                'result' => [
                    'value' => $result->result->value,
                    'label' => Msg::get('tests.statuses.'.$result->result->value),
                ],
                'examiner' => $result->recordedBy
                    ? ['id' => $result->recordedBy->id, 'name' => $result->recordedBy->name]
                    : null,
                'recorded_at' => $result->recorded_at?->toIso8601String(),
            ];
        })->values()->all();

        return ReportResponse::build($context, [
            'summary' => [
                'total' => $total,
                'recorded' => $total,
                'total_recorded' => $total,
                'passed' => $passed,
                'failed' => $failed,
                'no_show' => $noShow,
                'awaiting' => $pending,
                'awaiting_result' => $pending,
                'pass_rate' => ReportResponse::rate($passed, $total),
                'failure_rate' => ReportResponse::rate($failed, $total),
                'no_show_rate' => ReportResponse::rate($noShow, $total),
                'retests' => $retests,
                'average_attempts_before_passing' => $avgAttempts !== null ? round((float) $avgAttempts, 2) : null,
            ],
            'series' => ReportContract::namedSeries([
                'passed' => ReportSeriesBuilder::fill($context, $passedRows, 'count'),
                'failed' => ReportSeriesBuilder::fill($context, $failedRows, 'count'),
                'no_show' => ReportSeriesBuilder::fill($context, $noShowRows, 'count'),
            ]),
            'breakdowns' => ReportContract::aliasBreakdowns([
                'test_type' => ReportContract::breakdownItems($byType, 'code', 'name'),
                'result' => ReportContract::breakdownItems($byResult, 'result'),
                'attempt_number' => ReportContract::breakdownItems($byAttempt, 'attempt_number'),
            ]),
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
        $query = TestResult::query();
        $this->clock->applyUtcRange($query, 'recorded_at', $context['query_from'], $context['query_to_exclusive']);

        if (! empty($filters['test_type_code'])) {
            $query->whereHas('testType', fn (Builder $q) => $q->where('code', $filters['test_type_code']));
        }
        if (! empty($filters['test_result'])) {
            $query->where('result', $filters['test_result']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('recorded_by', (int) $filters['employee_id']);
        }
        if (! empty($filters['service_type_code']) || ! empty($filters['license_type_code'])) {
            $query->whereHas('application', function (Builder $app) use ($filters): void {
                if (! empty($filters['service_type_code'])) {
                    $app->whereHas('serviceType', fn (Builder $q) => $q->where('code', $filters['service_type_code']));
                }
                if (! empty($filters['license_type_code'])) {
                    $app->whereHas('licenseType', fn (Builder $q) => $q->where('code', $filters['license_type_code']));
                }
            });
        }

        return $query;
    }
}
