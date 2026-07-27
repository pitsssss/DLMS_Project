<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\DocumentStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\TestResult;
use App\Models\User;
use App\Modules\Dashboard\Support\Reports\ReportPeriodResolver;
use App\Modules\Dashboard\Support\Reports\ReportResponse;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;

class DashboardReportEmployeesService
{
    private const AUDIT_ACTIONS = [
        'document.approved',
        'document.rejected',
        'test_result.recorded',
        'license.issued',
    ];

    public function __construct(
        private readonly ReportPeriodResolver $periods,
        private readonly BusinessClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(User $viewer, array $filters): array
    {
        $context = $this->periods->resolve($filters);
        $canUseAudit = $viewer->hasPermission('view_audit_logs');

        $employeeQuery = User::query()
            ->whereIn('user_type', [UserType::Employee, UserType::Admin])
            ->where('is_active', true)
            ->orderBy('name');

        if (! empty($filters['employee_id'])) {
            $employeeQuery->whereKey((int) $filters['employee_id']);
        }

        $employees = $employeeQuery->with('role:id,name')->get(['id', 'name', 'role_id']);
        $employeeIds = $employees->pluck('id')->all();

        $documentCounts = $this->documentReviewCounts($context, $employeeIds);
        $testCounts = $this->testResultCounts($context, $employeeIds);
        $auditCounts = $canUseAudit ? $this->auditActionCounts($context, $employeeIds) : [];

        $rows = [];
        $ranking = [];
        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            $docs = (int) ($documentCounts[$id] ?? 0);
            $tests = (int) ($testCounts[$id] ?? 0);
            $licenses = (int) ($auditCounts[$id]['license.issued'] ?? 0);
            $appActions = (int) ($auditCounts[$id]['application_actions'] ?? 0);
            $auditTotal = (int) ($auditCounts[$id]['total'] ?? 0);

            $totalActions = $docs + $tests + ($canUseAudit ? $auditTotal : 0);

            $lastDoc = $documentCounts['_last'][$id] ?? null;
            $lastTest = $testCounts['_last'][$id] ?? null;
            $lastAudit = $canUseAudit ? ($auditCounts[$id]['last_activity'] ?? null) : null;
            $lastActivity = $this->maxIso([$lastDoc, $lastTest, $lastAudit]);

            $row = [
                'employee' => ['id' => $id, 'name' => $employee->name],
                'role' => $employee->role?->name,
                'total_actions' => $totalActions,
                'document_reviews_completed' => $docs,
                'test_results_recorded' => $tests,
                'licenses_issued' => $canUseAudit ? $licenses : null,
                'application_actions' => $canUseAudit ? $appActions : null,
                'last_activity_at' => $lastActivity,
                'limitations' => $canUseAudit ? null : 'Audit-based metrics require view_audit_logs.',
            ];
            $rows[] = $row;
            $ranking[] = ['employee_id' => $id, 'name' => $employee->name, 'total_actions' => $totalActions];
        }

        usort($ranking, fn ($a, $b) => $b['total_actions'] <=> $a['total_actions']);
        usort($rows, fn ($a, $b) => $b['total_actions'] <=> $a['total_actions']);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);
        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($rows, $offset, $perPage);

        return ReportResponse::build($context, [
            'summary' => [
                'employees_in_report' => $total,
                'total_document_reviews' => array_sum(array_map(fn ($id) => (int) ($documentCounts[$id] ?? 0), $employeeIds)),
                'total_test_results' => array_sum(array_map(fn ($id) => (int) ($testCounts[$id] ?? 0), $employeeIds)),
                'audit_metrics_included' => $canUseAudit,
            ],
            'series' => [
                ['key' => 'ranking', 'items' => array_slice($ranking, 0, 10)],
            ],
            'breakdowns' => [],
            'rows' => $pagedRows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int|string, mixed>
     */
    private function documentReviewCounts(array $context, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = ApplicationDocument::query()
            ->whereIn('reviewed_by', $employeeIds)
            ->whereIn('status', [DocumentStatus::Approved, DocumentStatus::Rejected])
            ->whereNotNull('reviewed_at');
        $this->clock->applyUtcRange($rows, 'reviewed_at', $context['query_from'], $context['query_to_exclusive']);

        $aggregates = (clone $rows)
            ->select('reviewed_by', DB::raw('COUNT(*) as aggregate_count'), DB::raw('MAX(reviewed_at) as last_reviewed'))
            ->groupBy('reviewed_by')
            ->get();

        $out = ['_last' => []];
        foreach ($aggregates as $row) {
            $id = (int) $row->reviewed_by;
            $out[$id] = (int) $row->aggregate_count;
            $out['_last'][$id] = $row->last_reviewed;
        }

        return $out;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int|string, mixed>
     */
    private function testResultCounts(array $context, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = TestResult::query()->whereIn('recorded_by', $employeeIds);
        $this->clock->applyUtcRange($rows, 'recorded_at', $context['query_from'], $context['query_to_exclusive']);

        $aggregates = (clone $rows)
            ->select('recorded_by', DB::raw('COUNT(*) as aggregate_count'), DB::raw('MAX(recorded_at) as last_recorded'))
            ->groupBy('recorded_by')
            ->get();

        $out = ['_last' => []];
        foreach ($aggregates as $row) {
            $id = (int) $row->recorded_by;
            $out[$id] = (int) $row->aggregate_count;
            $out['_last'][$id] = $row->last_recorded;
        }

        return $out;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, mixed>>
     */
    private function auditActionCounts(array $context, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $query = AuditLog::query()
            ->whereIn('user_id', $employeeIds)
            ->where(function ($q): void {
                $q->whereIn('action', self::AUDIT_ACTIONS)
                    ->orWhere(function ($inner): void {
                        $inner->where('entity_type', 'license_application')
                            ->where('action', 'like', 'application.%');
                    });
            });
        $this->clock->applyUtcRange($query, 'created_at', $context['query_from'], $context['query_to_exclusive']);

        $rows = $query->get(['user_id', 'action', 'entity_type', 'created_at']);
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->user_id;
            $out[$id] ??= [
                'total' => 0,
                'license.issued' => 0,
                'application_actions' => 0,
                'last_activity' => null,
            ];
            $out[$id]['total']++;
            if ($row->action === 'license.issued') {
                $out[$id]['license.issued']++;
            }
            if ($row->entity_type === 'license_application' || str_starts_with((string) $row->action, 'application.')) {
                $out[$id]['application_actions']++;
            }
            $out[$id]['last_activity'] = $this->maxIso([$out[$id]['last_activity'], $row->created_at?->toIso8601String()]);
        }

        return $out;
    }

    /**
     * @param  list<string|null>  $values
     */
    private function maxIso(array $values): ?string
    {
        $filtered = array_filter($values);
        if ($filtered === []) {
            return null;
        }

        return max($filtered);
    }
}
