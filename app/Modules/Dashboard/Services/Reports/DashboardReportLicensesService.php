<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\LicenseStatus;
use App\Enums\ServiceCode;
use App\Models\License;
use App\Modules\Dashboard\Support\Reports\ReportContract;
use App\Modules\Dashboard\Support\Reports\ReportPeriodResolver;
use App\Modules\Dashboard\Support\Reports\ReportResponse;
use App\Modules\Dashboard\Support\Reports\ReportSeriesBuilder;
use App\Modules\Licenses\Services\LicenseIssuanceEligibilityService;
use App\Modules\Licenses\Support\LicenseEffectiveStatus;
use App\Support\BusinessClock;
use App\Support\Msg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardReportLicensesService
{
    public function __construct(
        private readonly ReportPeriodResolver $periods,
        private readonly BusinessClock $clock,
        private readonly LicenseIssuanceEligibilityService $issuanceEligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $context = $this->periods->resolve($filters);
        $base = $this->filteredQuery($context, $filters);

        $issued = (clone $base)->count();
        $today = $this->clock->now()->toDateString();
        $active = (clone $base)
            ->where('status', LicenseStatus::Active)
            ->where('expiry_date', '>=', $today)
            ->count();
        $expired = (clone $base)
            ->where(function ($q) use ($today): void {
                $q->where('status', LicenseStatus::Expired)
                    ->orWhere(function ($inner) use ($today): void {
                        $inner->where('status', LicenseStatus::Active)
                            ->where('expiry_date', '<', $today);
                    });
            })
            ->count();
        $suspended = (clone $base)->whereIn('status', [LicenseStatus::Suspended, LicenseStatus::Blocked])->count();
        $renewed = (clone $base)->where('status', LicenseStatus::Renewed)->count();

        $replacement = (clone $base)
            ->whereHas('application.serviceType', function (Builder $q): void {
                $q->whereIn('code', [
                    ServiceCode::LostReplacement->value,
                    ServiceCode::DamagedReplacement->value,
                ]);
            })
            ->count();

        $readyForIssuance = $this->issuanceEligibility->readyCount();

        $bucketExpr = $this->periods->bucketExpression('issue_date', $context['group_by']);
        $issuedRows = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();

        $byType = (clone $base)
            ->join('license_types', 'license_types.id', '=', 'licenses.license_type_id')
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

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->get()
            ->map(function ($row) {
                $status = $row->status instanceof LicenseStatus ? $row->status->value : (string) $row->status;

                return [
                    'status' => $status,
                    'label' => Msg::get('licenses.statuses.'.$status),
                    'count' => (int) $row->aggregate_count,
                ];
            })
            ->values()
            ->all();

        $byService = (clone $base)
            ->join('license_applications', 'license_applications.id', '=', 'licenses.application_id')
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

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = (clone $base)
            ->with([
                'citizen:id,name',
                'licenseType:id,code,name',
                'application:id,application_number,service_type_id',
                'application.serviceType:id,code,name',
            ])
            ->orderByDesc('issue_date')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function (License $license) {
            $effective = LicenseEffectiveStatus::resolve($license);

            return [
                'license_number' => $license->license_number,
                'citizen' => $license->citizen
                    ? ['id' => $license->citizen->id, 'name' => $license->citizen->name]
                    : null,
                'license_type' => $license->licenseType
                    ? ['code' => $license->licenseType->code, 'name' => $license->licenseType->name]
                    : null,
                'status' => [
                    'value' => $effective->value,
                    'label' => Msg::get('licenses.statuses.'.$effective->value),
                ],
                'status_label' => Msg::get('licenses.statuses.'.$effective->value),
                'issue_date' => $license->issue_date?->toDateString(),
                'expiry_date' => $license->expiry_date?->toDateString(),
                'issued_at' => $license->issue_date?->toDateString(),
                'expires_at' => $license->expiry_date?->toDateString(),
                'service_type' => $license->application?->serviceType
                    ? ['code' => $license->application->serviceType->code, 'name' => $license->application->serviceType->name]
                    : null,
                'application_number' => $license->application?->application_number,
            ];
        })->values()->all();

        return ReportResponse::build($context, [
            'summary' => [
                'issued' => $issued,
                'active' => $active,
                'expired' => $expired,
                'suspended_or_blocked' => $suspended,
                'suspended' => $suspended,
                'blocked' => $suspended,
                'renewed' => $renewed,
                'replacement' => $replacement,
                'ready_for_issuance' => $readyForIssuance,
            ],
            'series' => ReportContract::namedSeries([
                'issued' => ReportSeriesBuilder::fill($context, $issuedRows, 'count'),
            ]),
            'breakdowns' => ReportContract::aliasBreakdowns([
                'license_type' => ReportContract::breakdownItems($byType, 'code', 'name'),
                'status' => ReportContract::breakdownItems($byStatus, 'status'),
                'service_type' => ReportContract::breakdownItems($byService, 'code', 'name'),
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
        $query = License::query();
        $query->where('issue_date', '>=', $context['date_from']->toDateString())
            ->where('issue_date', '<=', $context['date_to']->toDateString());

        if (! empty($filters['license_type_code'])) {
            $query->whereHas('licenseType', fn (Builder $q) => $q->where('code', $filters['license_type_code']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['service_type_code'])) {
            $query->whereHas('application.serviceType', fn (Builder $q) => $q->where('code', $filters['service_type_code']));
        }

        return $query;
    }
}
