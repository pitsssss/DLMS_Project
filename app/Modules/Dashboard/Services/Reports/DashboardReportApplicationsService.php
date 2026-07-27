<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Modules\Dashboard\Support\Reports\ReportPeriodResolver;
use App\Modules\Dashboard\Support\Reports\ReportResponse;
use App\Modules\Dashboard\Support\Reports\ReportSeriesBuilder;
use App\Support\BusinessClock;
use App\Support\EmployeeMessageTranslator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardReportApplicationsService
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

        $submitted = (clone $base)->count();
        $approved = (clone $base)->where('status', ApplicationStatus::Approved)->count();
        $rejected = (clone $base)->where('status', ApplicationStatus::Rejected)->count();
        $cancelled = (clone $base)->where('status', ApplicationStatus::Cancelled)->count();
        $issued = (clone $base)->where('status', ApplicationStatus::LicenseIssued)->count();
        $pending = (clone $base)->whereIn('status', ApplicationStatus::activeValues())->count();

        $decided = $approved + $rejected;

        $avgSeconds = (clone $base)
            ->whereNotNull('submitted_at')
            ->where(function (Builder $q): void {
                $q->whereNotNull('approved_at')
                    ->orWhereNotNull('issued_at')
                    ->orWhere('status', ApplicationStatus::Rejected);
            })
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, submitted_at, COALESCE(approved_at, issued_at, updated_at))) as avg_seconds')
            ->value('avg_seconds');

        $bucketExpr = $this->periods->bucketExpression('license_applications.created_at', $context['group_by']);
        $createdRows = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();

        $completedRows = (clone $base)
            ->whereIn('status', [ApplicationStatus::Approved, ApplicationStatus::LicenseIssued, ApplicationStatus::Rejected])
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();

        $statusBreakdown = (clone $base)
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status instanceof ApplicationStatus ? $row->status->value : (string) $row->status,
                'label' => EmployeeMessageTranslator::get('messages.employee.statuses.'.($row->status instanceof ApplicationStatus ? $row->status->value : $row->status)),
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();

        $serviceBreakdown = (clone $base)
            ->join('service_types', 'service_types.id', '=', 'license_applications.service_type_id')
            ->select('service_types.code', 'service_types.name', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('service_types.code', 'service_types.name')
            ->orderByDesc('aggregate_count')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();

        $licenseBreakdown = (clone $base)
            ->join('license_types', 'license_types.id', '=', 'license_applications.license_type_id')
            ->select('license_types.code', 'license_types.name', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('license_types.code', 'license_types.name')
            ->orderByDesc('aggregate_count')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = (clone $base)
            ->with(['citizen:id,name', 'serviceType:id,code,name', 'licenseType:id,code,name'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function (LicenseApplication $app) {
            $end = $app->approved_at ?? $app->issued_at ?? ($app->status === ApplicationStatus::Rejected ? $app->updated_at : null);
            $durationHours = null;
            if ($app->submitted_at && $end) {
                $durationHours = round($app->submitted_at->diffInMinutes($end) / 60, 2);
            }

            return [
                'application_number' => $app->application_number,
                'citizen' => ['id' => $app->citizen_id, 'name' => $app->citizen?->name],
                'service_type' => $app->serviceType ? ['code' => $app->serviceType->code, 'name' => $app->serviceType->name] : null,
                'license_type' => $app->licenseType ? ['code' => $app->licenseType->code, 'name' => $app->licenseType->name] : null,
                'status' => ['value' => $app->status->value, 'label' => EmployeeMessageTranslator::get('messages.employee.statuses.'.$app->status->value)],
                'created_at' => $app->created_at?->toIso8601String(),
                'approved_at' => $app->approved_at?->toIso8601String(),
                'rejected_at' => $app->status === ApplicationStatus::Rejected ? $app->updated_at?->toIso8601String() : null,
                'issued_at' => $app->issued_at?->toIso8601String(),
                'processing_duration_hours' => $durationHours,
            ];
        })->values()->all();

        return ReportResponse::build($context, [
            'summary' => [
                'submitted' => $submitted,
                'approved' => $approved,
                'rejected' => $rejected,
                'cancelled' => $cancelled,
                'license_issued' => $issued,
                'pending_in_progress' => $pending,
                'approval_rate' => ReportResponse::rate($approved, $decided),
                'rejection_rate' => ReportResponse::rate($rejected, $decided),
                'average_processing_hours' => $avgSeconds !== null ? round(((float) $avgSeconds) / 3600, 2) : null,
            ],
            'series' => [
                ['key' => 'created', 'items' => ReportSeriesBuilder::fill($context, $createdRows, 'count')],
                ['key' => 'completed', 'items' => ReportSeriesBuilder::fill($context, $completedRows, 'count')],
            ],
            'breakdowns' => [
                'by_status' => $statusBreakdown,
                'by_service_type' => $serviceBreakdown,
                'by_license_type' => $licenseBreakdown,
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
        $query = LicenseApplication::query();
        $this->clock->applyUtcRange($query, 'license_applications.created_at', $context['query_from'], $context['query_to_exclusive']);

        if (! empty($filters['application_status'])) {
            $query->where('status', $filters['application_status']);
        }
        if (! empty($filters['service_type_code'])) {
            $query->whereHas('serviceType', fn (Builder $q) => $q->where('code', $filters['service_type_code']));
        }
        if (! empty($filters['license_type_code'])) {
            $query->whereHas('licenseType', fn (Builder $q) => $q->where('code', $filters['license_type_code']));
        }
        if (! empty($filters['test_type_code'])) {
            $query->whereHas('currentTestType', fn (Builder $q) => $q->where('code', $filters['test_type_code']));
        }

        return $query;
    }
}
