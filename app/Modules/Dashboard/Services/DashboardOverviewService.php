<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\User;
use App\Modules\Licenses\Services\LicenseIssuanceEligibilityService;
use App\Support\BusinessClock;
use App\Support\EmployeeMessageTranslator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardOverviewService
{
    public function __construct(
        private readonly LicenseIssuanceEligibilityService $issuanceEligibility,
        private readonly BusinessClock $clock,
    ) {}

    /**
     * Application statuses that require dashboard staff action.
     *
     * @var list<ApplicationStatus>
     */
    private const PENDING_ACTION_STATUSES = [
        ApplicationStatus::DocumentsUnderReview,
        ApplicationStatus::AdministrativeReview,
        ApplicationStatus::Approved,
    ];

    /**
     * @param  array{period: string, recent_limit: int, activity_limit: int}  $filters
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters): array
    {
        $visibility = $this->visibility($user);
        $periodMeta = $this->clock->resolvePeriod($filters['period']);

        $kpis = [
            'applications' => null,
            'citizens' => null,
            'employees' => null,
            'licenses' => null,
            'payments' => null,
            'appointments' => null,
            'tests' => null,
            'fines' => null,
        ];

        $queues = [
            'applications_pending_action' => null,
            'documents_pending_review' => null,
            'payments_pending' => null,
            'tests_awaiting_result' => null,
            'licenses_ready_for_issuance' => null,
            'appointments_today' => null,
        ];

        $charts = [
            'applications_trend' => null,
            'application_status_distribution' => null,
            'service_type_distribution' => null,
        ];

        $recentApplications = null;
        $upcomingAppointments = null;
        $recentActivities = null;

        if ($visibility['applications']) {
            $kpis['applications'] = $this->applicationsKpi($periodMeta);
            $queues['applications_pending_action'] = $kpis['applications']['pending_action'];
            $charts['applications_trend'] = $this->applicationsTrend($periodMeta);
            $charts['application_status_distribution'] = $this->statusDistribution();
            $charts['service_type_distribution'] = $this->serviceTypeDistribution($periodMeta);
            $recentApplications = $this->recentApplications($filters['recent_limit']);
        }

        if ($visibility['citizens']) {
            $kpis['citizens'] = $this->citizensKpi($periodMeta);
        }

        if ($visibility['employees']) {
            $kpis['employees'] = $this->employeesKpi();
        }

        if ($visibility['licenses']) {
            $kpis['licenses'] = $this->licensesKpi($periodMeta);
            $queues['licenses_ready_for_issuance'] = $this->issuanceEligibility->readyCount();
        }

        if ($visibility['payments']) {
            $kpis['payments'] = $this->paymentsKpi($periodMeta);
            $queues['payments_pending'] = Payment::query()
                ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::UnderVerification])
                ->count();
        }

        if ($visibility['appointments']) {
            $kpis['appointments'] = $this->appointmentsKpi($periodMeta);
            $queues['appointments_today'] = $kpis['appointments']['today'];
            $upcomingAppointments = $this->upcomingAppointments($filters['recent_limit']);
        }

        if ($visibility['tests']) {
            $kpis['tests'] = $this->testsKpi($periodMeta);
            $queues['tests_awaiting_result'] = $kpis['tests']['awaiting_result'];
        }

        if ($visibility['fines']) {
            $kpis['fines'] = $this->finesKpi($periodMeta);
        }

        if ($visibility['documents']) {
            $queues['documents_pending_review'] = ApplicationDocument::query()
                ->where('status', DocumentStatus::PendingReview)
                ->whereHas('application', fn (Builder $q) => $q->where('status', ApplicationStatus::DocumentsUnderReview))
                ->count();
        }

        if ($visibility['recent_activities']) {
            $recentActivities = $this->recentActivities($filters['activity_limit']);
        }

        return [
            'meta' => [
                'period' => $periodMeta['period'],
                'date_from' => $periodMeta['current_from']->toIso8601String(),
                'date_to' => $periodMeta['current_to']->toIso8601String(),
                'previous_date_from' => $periodMeta['previous_from']->toIso8601String(),
                'previous_date_to' => $periodMeta['previous_to']->toIso8601String(),
                'trend_granularity' => $periodMeta['granularity'],
                'timezone' => $periodMeta['timezone'],
                'generated_at' => $this->clock->now()->toIso8601String(),
            ],
            'visibility' => [
                'applications' => $visibility['applications'],
                'citizens' => $visibility['citizens'],
                'employees' => $visibility['employees'],
                'licenses' => $visibility['licenses'],
                'payments' => $visibility['payments'],
                'appointments' => $visibility['appointments'],
                'tests' => $visibility['tests'],
                'fines' => $visibility['fines'],
                'recent_activities' => $visibility['recent_activities'],
            ],
            'kpis' => $kpis,
            'operational_queues' => $queues,
            'charts' => $charts,
            'recent_applications' => $recentApplications,
            'upcoming_appointments' => $upcomingAppointments,
            'recent_activities' => $recentActivities,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function visibility(User $user): array
    {
        return [
            'applications' => $user->hasPermission('view_applications') || $user->hasPermission('manage_applications'),
            'citizens' => $user->hasPermission('manage_users'),
            'employees' => $user->hasPermission('manage_employees') || $user->hasPermission('view_employees'),
            'licenses' => $user->hasPermission('view_licenses') || $user->hasPermission('manage_licenses') || $user->hasPermission('issue_license'),
            'payments' => $user->hasPermission('view_payments') || $user->hasPermission('manage_payments'),
            'appointments' => $user->hasPermission('view_appointments') || $user->hasPermission('manage_appointments'),
            'tests' => $user->hasPermission('record_test_result') || $user->hasPermission('manage_appointments') || $user->hasPermission('view_appointments'),
            'fines' => $user->hasPermission('view_fines') || $user->hasPermission('manage_fines'),
            'documents' => $user->hasPermission('review_documents'),
            'recent_activities' => $user->hasPermission('view_audit_logs'),
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function applicationsKpi(array $period): array
    {
        $total = LicenseApplication::query()->count();
        $current = LicenseApplication::query()
            ->whereBetween('created_at', [$period['query_current_from'], $period['query_current_to']])
            ->count();
        $previous = LicenseApplication::query()
            ->whereBetween('created_at', [$period['query_previous_from'], $period['query_previous_to']])
            ->count();

        $comparison = $this->compare($current, $previous);

        // Broader operational queue than licenses_ready_for_issuance (approved ⊂ pending_action).
        $pendingAction = LicenseApplication::query()
            ->whereIn('status', array_map(fn (ApplicationStatus $s) => $s->value, self::PENDING_ACTION_STATUSES))
            ->count();

        $approved = LicenseApplication::query()
            ->whereNotNull('approved_at')
            ->whereBetween('approved_at', [$period['query_current_from'], $period['query_current_to']])
            ->count();

        $rejected = LicenseApplication::query()
            ->where('status', ApplicationStatus::Rejected)
            ->whereBetween('updated_at', [$period['query_current_from'], $period['query_current_to']])
            ->count();

        return [
            'total' => $total,
            'current_period' => $current,
            'previous_period' => $previous,
            'change_percentage' => $comparison['change_percentage'],
            'trend' => $comparison['trend'],
            'pending_action' => $pendingAction,
            'approved_current_period' => $approved,
            'rejected_current_period' => $rejected,
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array<string, int>
     */
    private function citizensKpi(array $period): array
    {
        $base = User::query()->where('user_type', UserType::Citizen);
        $total = (clone $base)->count();
        $active = (clone $base)->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'complete_profiles' => (clone $base)->where('profile_completed', true)->count(),
            'incomplete_profiles' => (clone $base)->where('profile_completed', false)->count(),
            'registered_current_period' => (clone $base)
                ->whereBetween('created_at', [$period['query_current_from'], $period['query_current_to']])
                ->count(),
        ];
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    private function employeesKpi(): array
    {
        $base = User::query()->whereIn('user_type', [UserType::Employee, UserType::Admin]);
        $total = (clone $base)->count();
        $active = (clone $base)->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function licensesKpi(array $period): array
    {
        $issuedTotal = License::query()->count();
        $current = License::query()
            ->whereBetween('issue_date', [
                $period['current_from']->toDateString(),
                $period['current_to']->toDateString(),
            ])
            ->count();
        $previous = License::query()
            ->whereBetween('issue_date', [
                $period['previous_from']->toDateString(),
                $period['previous_to']->toDateString(),
            ])
            ->count();
        $comparison = $this->compare($current, $previous);

        return [
            'issued_total' => $issuedTotal,
            'issued_current_period' => $current,
            'issued_previous_period' => $previous,
            'change_percentage' => $comparison['change_percentage'],
            'trend' => $comparison['trend'],
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function paymentsKpi(array $period): array
    {
        $current = Payment::query()
            ->where('status', PaymentStatus::Completed)
            ->where(function (Builder $q) use ($period): void {
                $q->whereBetween('paid_at', [$period['query_current_from'], $period['query_current_to']])
                    ->orWhere(function (Builder $inner) use ($period): void {
                        $inner->whereNull('paid_at')
                            ->whereBetween('updated_at', [$period['query_current_from'], $period['query_current_to']]);
                    });
            })
            ->selectRaw('count(*) as total_count, coalesce(sum(amount), 0) as total_amount')
            ->first();

        $previous = Payment::query()
            ->where('status', PaymentStatus::Completed)
            ->where(function (Builder $q) use ($period): void {
                $q->whereBetween('paid_at', [$period['query_previous_from'], $period['query_previous_to']])
                    ->orWhere(function (Builder $inner) use ($period): void {
                        $inner->whereNull('paid_at')
                            ->whereBetween('updated_at', [$period['query_previous_from'], $period['query_previous_to']]);
                    });
            })
            ->selectRaw('count(*) as total_count, coalesce(sum(amount), 0) as total_amount')
            ->first();

        $currentAmount = (string) ($current->total_amount ?? '0');
        $previousAmount = (string) ($previous->total_amount ?? '0');
        $comparison = $this->compare((float) $currentAmount, (float) $previousAmount);

        return [
            'paid_count_current_period' => (int) ($current->total_count ?? 0),
            'paid_amount_current_period' => $this->money($currentAmount),
            'paid_count_previous_period' => (int) ($previous->total_count ?? 0),
            'paid_amount_previous_period' => $this->money($previousAmount),
            'amount_change_percentage' => $comparison['change_percentage'],
            'trend' => $comparison['trend'],
            'pending_count' => Payment::query()
                ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::UnderVerification])
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array{today: int, upcoming_7_days: int, pending_confirmation: int}
     */
    private function appointmentsKpi(array $period): array
    {
        $todayStart = $this->clock->now()->startOfDay();
        $todayEnd = $this->clock->now()->endOfDay();
        $weekEnd = $todayStart->addDays(6)->endOfDay();

        $active = [AppointmentStatus::Booked];

        return [
            'today' => TestAppointment::query()
                ->whereIn('status', $active)
                ->whereBetween('scheduled_at', [$this->clock->toUtc($todayStart), $this->clock->toUtc($todayEnd)])
                ->count(),
            'upcoming_7_days' => TestAppointment::query()
                ->whereIn('status', $active)
                ->whereBetween('scheduled_at', [$this->clock->toUtc($todayStart), $this->clock->toUtc($weekEnd)])
                ->count(),
            // No pending_confirmation status exists in AppointmentStatus.
            'pending_confirmation' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function testsKpi(array $period): array
    {
        $completedQuery = TestResult::query()
            ->whereIn('result', [TestResultStatus::Passed, TestResultStatus::Failed])
            ->whereBetween('recorded_at', [$period['query_current_from'], $period['query_current_to']]);

        $completed = (clone $completedQuery)->count();
        $passed = TestResult::query()
            ->where('result', TestResultStatus::Passed)
            ->whereBetween('recorded_at', [$period['query_current_from'], $period['query_current_to']])
            ->count();
        $failed = TestResult::query()
            ->where('result', TestResultStatus::Failed)
            ->whereBetween('recorded_at', [$period['query_current_from'], $period['query_current_to']])
            ->count();

        $awaiting = TestAppointment::query()
            ->where('status', AppointmentStatus::Booked)
            ->whereDoesntHave('testResult')
            ->count();

        return [
            'completed_current_period' => $completed,
            'passed_current_period' => $passed,
            'failed_current_period' => $failed,
            'pass_rate' => $completed > 0 ? round(($passed / $completed) * 100, 2) : null,
            'awaiting_result' => $awaiting,
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function finesKpi(array $period): array
    {
        $unpaid = Fine::query()
            ->where('status', FineStatus::Unpaid)
            ->selectRaw('count(*) as total_count, coalesce(sum(amount), 0) as total_amount')
            ->first();

        $paid = Fine::query()
            ->where('status', FineStatus::Paid)
            ->whereBetween('paid_at', [$period['query_current_from'], $period['query_current_to']])
            ->selectRaw('count(*) as total_count, coalesce(sum(amount), 0) as total_amount')
            ->first();

        return [
            'unpaid_count' => (int) ($unpaid->total_count ?? 0),
            'unpaid_amount' => $this->money($unpaid->total_amount ?? 0),
            'paid_current_period_count' => (int) ($paid->total_count ?? 0),
            'paid_current_period_amount' => $this->money($paid->total_amount ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array{granularity: string, items: list<array{bucket: string, count: int}>}
     */
    private function applicationsTrend(array $period): array
    {
        $granularity = $period['granularity'];
        $expr = $granularity === 'month'
            ? $this->clock->sqlBusinessMonthExpression('created_at')
            : $this->clock->sqlBusinessDateExpression('created_at');

        // Aggregate UTC-stored created_at into business-local day/month buckets.
        $rows = LicenseApplication::query()
            ->whereBetween('created_at', [$period['query_current_from'], $period['query_current_to']])
            ->selectRaw("{$expr} as bucket, count(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($count) => (int) $count)
            ->all();

        $items = [];
        if ($granularity === 'month') {
            $cursor = $period['current_from']->startOfMonth();
            $end = $period['current_to']->startOfMonth();
            while ($cursor->lte($end)) {
                $bucket = $cursor->format('Y-m');
                $items[] = ['bucket' => $bucket, 'count' => $rows[$bucket] ?? 0];
                $cursor = $cursor->addMonth();
            }
        } else {
            $cursor = $period['current_from']->startOfDay();
            $end = $period['current_to']->startOfDay();
            while ($cursor->lte($end)) {
                $bucket = $cursor->format('Y-m-d');
                $items[] = ['bucket' => $bucket, 'count' => $rows[$bucket] ?? 0];
                $cursor = $cursor->addDay();
            }
        }

        return [
            'granularity' => $granularity,
            'items' => $items,
        ];
    }

    /**
     * @return list<array{status: string, label: string, count: int, percentage: float}>
     */
    private function statusDistribution(): array
    {
        $counts = LicenseApplication::query()
            ->select('status', DB::raw('count(*) as aggregate_count'))
            ->groupBy('status')
            ->pluck('aggregate_count', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        $total = array_sum($counts);
        $items = [];

        foreach (ApplicationStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;
            $items[] = [
                'status' => $status->value,
                'label' => EmployeeMessageTranslator::get('messages.employee.statuses.'.$status->value),
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0.0,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $period
     * @return list<array<string, mixed>>
     */
    private function serviceTypeDistribution(array $period): array
    {
        $rows = LicenseApplication::query()
            ->whereBetween('created_at', [$period['query_current_from'], $period['query_current_to']])
            ->select('service_type_id', DB::raw('count(*) as aggregate_count'))
            ->groupBy('service_type_id')
            ->orderByDesc('aggregate_count')
            ->get();

        $total = (int) $rows->sum('aggregate_count');
        if ($total === 0) {
            return [];
        }

        $serviceTypes = ServiceType::query()
            ->whereIn('id', $rows->pluck('service_type_id')->filter()->all())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $items = [];
        if ($rows->count() <= 10) {
            foreach ($rows as $row) {
                $type = $serviceTypes->get($row->service_type_id);
                $count = (int) $row->aggregate_count;
                $items[] = [
                    'service_type_id' => $row->service_type_id,
                    'code' => $type?->code,
                    'name' => $type?->name ?? '—',
                    'count' => $count,
                    'percentage' => round(($count / $total) * 100, 2),
                ];
            }

            return $items;
        }

        $top = $rows->take(9);
        $otherCount = (int) $rows->slice(9)->sum('aggregate_count');

        foreach ($top as $row) {
            $type = $serviceTypes->get($row->service_type_id);
            $count = (int) $row->aggregate_count;
            $items[] = [
                'service_type_id' => $row->service_type_id,
                'code' => $type?->code,
                'name' => $type?->name ?? '—',
                'count' => $count,
                'percentage' => round(($count / $total) * 100, 2),
            ];
        }

        $items[] = [
            'service_type_id' => null,
            'code' => 'other',
            'name' => EmployeeMessageTranslator::get('messages.dashboard.overview_other'),
            'count' => $otherCount,
            'percentage' => round(($otherCount / $total) * 100, 2),
        ];

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentApplications(int $limit): array
    {
        $apps = LicenseApplication::query()
            ->with([
                'citizen:id,name',
                'serviceType:id,code,name',
                'licenseType:id,code,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'application_number', 'citizen_id', 'service_type_id', 'license_type_id', 'status', 'created_at']);

        return $apps->map(function (LicenseApplication $app): array {
            $status = $app->status instanceof ApplicationStatus
                ? $app->status->value
                : (string) $app->status;

            return [
                'id' => $app->id,
                'application_number' => $app->application_number,
                'citizen' => $app->citizen ? [
                    'id' => $app->citizen->id,
                    'name' => $app->citizen->name,
                ] : null,
                'service_type' => $app->serviceType ? [
                    'id' => $app->serviceType->id,
                    'code' => $app->serviceType->code,
                    'name' => $app->serviceType->name,
                ] : null,
                'license_type' => $app->licenseType ? [
                    'id' => $app->licenseType->id,
                    'code' => $app->licenseType->code,
                    'name' => $app->licenseType->name,
                ] : null,
                'status' => [
                    'value' => $status,
                    'label' => EmployeeMessageTranslator::get('messages.employee.statuses.'.$status),
                ],
                'created_at' => $app->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcomingAppointments(int $limit): array
    {
        $from = $this->clock->toUtc($this->clock->now());

        $appointments = TestAppointment::query()
            ->with([
                'application:id,application_number',
                'citizen:id,name',
                'testType:id,code,name',
            ])
            ->where('status', AppointmentStatus::Booked)
            ->where('scheduled_at', '>=', $from)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'application_id', 'citizen_id', 'test_type_id', 'scheduled_at', 'status']);

        return $appointments->map(function (TestAppointment $appointment): array {
            $status = $appointment->status instanceof AppointmentStatus
                ? $appointment->status->value
                : (string) $appointment->status;

            return [
                'id' => $appointment->id,
                'application' => $appointment->application ? [
                    'id' => $appointment->application->id,
                    'application_number' => $appointment->application->application_number,
                ] : null,
                'citizen' => $appointment->citizen ? [
                    'id' => $appointment->citizen->id,
                    'name' => $appointment->citizen->name,
                ] : null,
                'test_type' => $appointment->testType ? [
                    'value' => $appointment->testType->code,
                    'label' => $appointment->testType->name,
                ] : null,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'status' => [
                    'value' => $status,
                    'label' => $this->appointmentStatusLabel($status),
                ],
                'location' => null,
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentActivities(int $limit): array
    {
        $logs = AuditLog::query()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'user_id', 'action', 'entity_type', 'entity_id', 'created_at']);

        return $logs->map(fn (AuditLog $log): array => [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => $this->auditActionLabel((string) $log->action),
            'actor' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'entity' => [
                'type' => $log->entity_type,
                'id' => $log->entity_id,
                'display_name' => null,
            ],
            'created_at' => $log->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return array{change_percentage: float|null, trend: string}
     */
    private function compare(float|int $current, float|int $previous): array
    {
        if ((float) $previous === 0.0 && (float) $current === 0.0) {
            return ['change_percentage' => 0.0, 'trend' => 'flat'];
        }

        if ((float) $previous === 0.0 && (float) $current > 0.0) {
            return ['change_percentage' => null, 'trend' => 'not_comparable'];
        }

        $percentage = round((((float) $current - (float) $previous) / (float) $previous) * 100, 2);

        $trend = 'flat';
        if ($percentage > 0) {
            $trend = 'up';
        } elseif ($percentage < 0) {
            $trend = 'down';
        }

        return [
            'change_percentage' => $percentage,
            'trend' => $trend,
        ];
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function appointmentStatusLabel(string $status): string
    {
        return match ($status) {
            'booked' => 'محجوز',
            'cancelled' => 'ملغي',
            'completed' => 'مكتمل',
            'no_show' => 'لم يحضر',
            default => $status,
        };
    }

    private function auditActionLabel(string $action): string
    {
        return match ($action) {
            'employee.created' => 'إنشاء موظف',
            'employee.updated' => 'تعديل موظف',
            'employee.activated' => 'تفعيل موظف',
            'employee.deactivated' => 'تعطيل موظف',
            'employee.password_reset' => 'إعادة تعيين كلمة مرور موظف',
            'employee.role_assigned' => 'إسناد دور لموظف',
            'license_type.created' => 'إنشاء نوع رخصة',
            'license_type.updated' => 'تعديل نوع رخصة',
            'license_type.activated' => 'تفعيل نوع رخصة',
            'license_type.deactivated' => 'تعطيل نوع رخصة',
            'service_type.created' => 'إنشاء نوع خدمة',
            'service_type.updated' => 'تعديل نوع خدمة',
            'service_type.activated' => 'تفعيل نوع خدمة',
            'service_type.deactivated' => 'تعطيل نوع خدمة',
            'application.created' => 'إنشاء طلب',
            'application.status_changed' => 'تغيير حالة طلب',
            'document.approved' => 'قبول وثيقة',
            'document.rejected' => 'رفض وثيقة',
            'payment.completed' => 'إكمال دفع',
            'test_result.recorded' => 'تسجيل نتيجة اختبار',
            'license.issued' => 'إصدار رخصة',
            'license.blocked' => 'حظر رخصة',
            'license.unblocked' => 'فك حظر رخصة',
            'fine.created' => 'إنشاء غرامة',
            'fine.paid' => 'دفع غرامة',
            'citizen.updated' => 'تعديل مواطن',
            default => $action,
        };
    }
}
