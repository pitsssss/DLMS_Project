<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\User;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Dashboard\Support\Reports\ReportContract;
use App\Modules\Dashboard\Support\Reports\ReportPaymentMetrics;
use App\Modules\Dashboard\Support\Reports\ReportPeriodResolver;
use App\Modules\Dashboard\Support\Reports\ReportResponse;
use App\Modules\Dashboard\Support\Reports\ReportSeriesBuilder;
use App\Modules\Dashboard\Support\Reports\ReportVisibility;
use App\Modules\Licenses\Services\LicenseIssuanceEligibilityService;
use App\Support\BusinessClock;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardReportSummaryService
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
    public function build(User $user, array $filters): array
    {
        $context = $this->periods->resolve($filters);
        $visibility = ReportVisibility::for($user);
        $sections = [];
        $summary = [];
        $seriesNamed = [];
        $breakdowns = [];
        $kpis = [];
        $operational = [];
        $financial = [];

        if ($visibility['applications']) {
            $appQuery = LicenseApplication::query();
            $this->clock->applyUtcRange($appQuery, 'license_applications.created_at', $context['query_from'], $context['query_to_exclusive']);
            $submitted = (clone $appQuery)->count();
            $approved = (clone $appQuery)->where('status', ApplicationStatus::Approved)->count();
            $issued = (clone $appQuery)->where('status', ApplicationStatus::LicenseIssued)->count();
            $rejected = (clone $appQuery)->where('status', ApplicationStatus::Rejected)->count();
            $pending = (clone $appQuery)->whereIn('status', ApplicationStatus::activeValues())->count();

            $summary['applications'] = [
                'submitted' => $submitted,
                'approved' => $approved,
                'rejected' => $rejected,
                'license_issued' => $issued,
                'pending_in_progress' => $pending,
                'approval_rate' => ReportResponse::rate($approved, $approved + $rejected),
            ];
            $kpis['applications_total'] = $submitted;
            $kpis['applications_approved'] = $approved;
            $kpis['applications_issued'] = $issued;
            $kpis['applications_pending'] = $pending;
            $sections['applications'] = true;

            $createdRows = $this->bucketCounts(clone $appQuery, 'license_applications.created_at', $context);
            $completedQuery = (clone $appQuery)->whereIn('status', [
                ApplicationStatus::Approved,
                ApplicationStatus::LicenseIssued,
                ApplicationStatus::Rejected,
            ]);
            $completedRows = $this->bucketCounts($completedQuery, 'license_applications.created_at', $context);
            $seriesNamed['applications_created'] = ReportSeriesBuilder::fill($context, $createdRows, 'count');
            $seriesNamed['applications_completed'] = ReportSeriesBuilder::fill($context, $completedRows, 'count');

            $statusBreakdown = (clone $appQuery)
                ->select('license_applications.status', DB::raw('COUNT(*) as aggregate_count'))
                ->groupBy('license_applications.status')
                ->get()
                ->map(function ($row) {
                    $status = $row->status instanceof ApplicationStatus ? $row->status->value : (string) $row->status;

                    return [
                        'status' => $status,
                        'label' => EmployeeMessageTranslator::get('messages.employee.statuses.'.$status),
                        'count' => (int) $row->aggregate_count,
                    ];
                })
                ->values()
                ->all();

            $serviceBreakdown = (clone $appQuery)
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

            $breakdowns['status'] = ReportContract::breakdownItems($statusBreakdown, 'status');
            $breakdowns['service_type'] = ReportContract::breakdownItems($serviceBreakdown, 'code', 'name');

            $operational['applications_pending_action'] = $pending;
        }

        if ($visibility['citizens']) {
            $citizenQuery = User::query()->where('user_type', UserType::Citizen);
            $this->clock->applyUtcRange($citizenQuery, 'users.created_at', $context['query_from'], $context['query_to_exclusive']);
            $registered = (clone $citizenQuery)->count();
            $active = User::query()->where('user_type', UserType::Citizen)->where('is_active', true)->count();
            $summary['citizens'] = [
                'registered' => $registered,
                'active' => $active,
            ];
            $kpis['citizens_total'] = $registered;
            $sections['citizens'] = true;
        }

        if ($visibility['document_reviews']) {
            $docQuery = ApplicationDocument::query()->where('status', DocumentStatus::PendingReview);
            $this->clock->applyUtcRange($docQuery, 'application_documents.created_at', $context['query_from'], $context['query_to_exclusive']);
            $reviewedQuery = ApplicationDocument::query()
                ->whereIn('status', [DocumentStatus::Approved, DocumentStatus::Rejected])
                ->whereNotNull('reviewed_at');
            $this->clock->applyUtcRange($reviewedQuery, 'reviewed_at', $context['query_from'], $context['query_to_exclusive']);

            $pendingReview = (clone $docQuery)->count();
            $summary['document_reviews'] = [
                'pending_review' => $pendingReview,
                'reviewed_in_period' => (clone $reviewedQuery)->count(),
            ];
            $kpis['documents_pending_review'] = $pendingReview;
            $operational['documents_awaiting_review'] = $pendingReview;
            $sections['document_reviews'] = true;
        }

        if ($visibility['tests']) {
            $testQuery = TestResult::query();
            $this->clock->applyUtcRange($testQuery, 'test_results.recorded_at', $context['query_from'], $context['query_to_exclusive']);
            $total = (clone $testQuery)->count();
            $passed = (clone $testQuery)->where('result', TestResultStatus::Passed)->count();
            $passRate = ReportResponse::rate($passed, $total);

            $summary['tests'] = [
                'recorded' => $total,
                'passed' => $passed,
                'pass_rate' => $passRate,
            ];
            $kpis['tests_total'] = $total;
            $kpis['tests_pass_rate'] = $passRate;
            $sections['tests'] = true;

            $seriesNamed['tests_recorded'] = ReportSeriesBuilder::fill(
                $context,
                $this->bucketCounts(clone $testQuery, 'test_results.recorded_at', $context),
                'count'
            );
        }

        if ($visibility['appointments']) {
            $apptQuery = TestAppointment::query();
            $this->clock->applyUtcRange($apptQuery, 'test_appointments.scheduled_at', $context['query_from'], $context['query_to_exclusive']);
            $scheduled = (clone $apptQuery)->count();
            $upcoming = (clone $apptQuery)
                ->where('status', AppointmentStatus::Booked)
                ->where('scheduled_at', '>=', $this->clock->now()->utc())
                ->count();

            $summary['appointments'] = [
                'scheduled' => $scheduled,
                'completed' => (clone $apptQuery)->where('status', AppointmentStatus::Completed)->count(),
                'no_show' => (clone $apptQuery)->where('status', AppointmentStatus::NoShow)->count(),
                'upcoming' => $upcoming,
            ];
            $kpis['appointments_total'] = $scheduled;
            $kpis['appointments_upcoming'] = $upcoming;
            $operational['appointments_upcoming'] = $upcoming;
            $sections['appointments'] = true;
        }

        if ($visibility['licenses']) {
            $licenseQuery = License::query()
                ->where('licenses.issue_date', '>=', $context['date_from']->toDateString())
                ->where('licenses.issue_date', '<=', $context['date_to']->toDateString());
            $issuedInPeriod = (clone $licenseQuery)->count();
            $ready = $this->issuanceEligibility->readyCount();

            $summary['licenses'] = [
                'issued_in_period' => $issuedInPeriod,
                'ready_for_issuance' => $ready,
            ];
            $kpis['licenses_issued'] = $issuedInPeriod;
            $kpis['licenses_ready_for_issuance'] = $ready;
            $operational['licenses_ready_for_issuance'] = $ready;
            $sections['licenses'] = true;

            $seriesNamed['licenses_issued'] = ReportSeriesBuilder::fill(
                $context,
                $this->bucketCounts(clone $licenseQuery, 'licenses.issue_date', $context),
                'count'
            );
        }

        if ($visibility['payments']) {
            $range = fn ($q) => $this->clock->applyUtcRange(
                $q,
                'payments.created_at',
                $context['query_from'],
                $context['query_to_exclusive']
            );
            $payments = ReportPaymentMetrics::applicationPayments($range);
            $due = ReportPaymentMetrics::dueFees();

            $summary['application_payments'] = [
                'completed_count' => $payments['completed_count'],
                'completed_amount' => $payments['completed_amount'],
                'completed_amount_by_currency' => $payments['completed_amount_by_currency'],
                'currency' => $payments['currency'],
                'pending_count' => $payments['pending_count'],
            ];
            $summary['due_fees'] = $due;
            $kpis['payments_completed_count'] = $payments['completed_count'];
            $kpis['payments_completed_amount'] = $payments['completed_amount'];
            $kpis['payments_completed_by_currency'] = $payments['completed_amount_by_currency'];
            $kpis['due_fees_count'] = $due['count'];
            $kpis['due_fees_amount'] = $due['amount'];
            $operational['payments_pending'] = $payments['pending_count'];
            $operational['due_fee_obligations'] = $due['count'];
            $sections['payments'] = true;

            $paymentQuery = Payment::query()->whereNull('fine_id');
            $this->clock->applyUtcRange($paymentQuery, 'payments.created_at', $context['query_from'], $context['query_to_exclusive']);
            $completedPayments = (clone $paymentQuery)->where('status', PaymentStatus::Completed);
            $seriesNamed['payments_completed'] = ReportSeriesBuilder::fill(
                $context,
                $this->bucketCounts($completedPayments, 'payments.created_at', $context),
                'count'
            );

            $paymentStatusBreakdown = (clone $paymentQuery)
                ->select('status', DB::raw('COUNT(*) as aggregate_count'))
                ->groupBy('status')
                ->get()
                ->map(fn ($row) => [
                    'status' => $row->status instanceof PaymentStatus ? $row->status->value : (string) $row->status,
                    'label' => DashboardPaymentPresenter::paymentStatus(
                        $row->status instanceof PaymentStatus ? $row->status : PaymentStatus::tryFrom((string) $row->status)
                    )['label'],
                    'count' => (int) $row->aggregate_count,
                ])
                ->values()
                ->all();
            $breakdowns['payment_status'] = ReportContract::breakdownItems($paymentStatusBreakdown, 'status');

            $financial['completed_payments'] = ReportContract::moneyTotals(
                $payments['completed_amount_by_currency'],
                $payments['completed_amount'],
                $payments['currency']
            );
            $financial['due_fees'] = ReportContract::moneyTotals(null, $due['amount'], $payments['currency']);
        }

        if ($visibility['fines']) {
            $fineQuery = Fine::query();
            $this->clock->applyUtcRange($fineQuery, 'fines.created_at', $context['query_from'], $context['query_to_exclusive']);
            $totalFines = (clone $fineQuery)->count();
            $unpaidAmount = (clone $fineQuery)->where('status', FineStatus::Unpaid)->sum('amount');
            $unpaidCount = Fine::query()->where('status', FineStatus::Unpaid)->count();
            $paidAmount = (clone $fineQuery)->where('status', FineStatus::Paid)->sum('amount');

            $summary['fines'] = [
                'total_in_period' => $totalFines,
                'unpaid' => $unpaidCount,
                'unpaid_amount' => DashboardPaymentPresenter::money($unpaidAmount),
                'paid_amount' => DashboardPaymentPresenter::money($paidAmount),
            ];
            $kpis['fines_total'] = $totalFines;
            $kpis['fines_unpaid_count'] = $unpaidCount;
            $kpis['fines_unpaid_amount'] = DashboardPaymentPresenter::money($unpaidAmount);
            $sections['fines'] = true;

            $seriesNamed['fines_created'] = ReportSeriesBuilder::fill(
                $context,
                $this->bucketCounts(clone $fineQuery, 'fines.created_at', $context),
                'count'
            );

            $fineStatusBreakdown = (clone $fineQuery)
                ->select('status', DB::raw('COUNT(*) as aggregate_count'))
                ->groupBy('status')
                ->get()
                ->map(function ($row) {
                    $status = $row->status instanceof FineStatus ? $row->status->value : (string) $row->status;

                    return [
                        'status' => $status,
                        'label' => Msg::get('fines.statuses.'.$status),
                        'count' => (int) $row->aggregate_count,
                    ];
                })
                ->values()
                ->all();
            $breakdowns['fine_status'] = ReportContract::breakdownItems($fineStatusBreakdown, 'status');

            $fineCurrency = (clone $fineQuery)->whereNotNull('currency')->distinct()->pluck('currency')->filter()->values();
            $fineCurrencyCode = $fineCurrency->count() === 1 ? (string) $fineCurrency->first() : ($fineCurrency->count() === 0 ? 'USD' : null);
            $financial['fines'] = ReportContract::moneyTotals(
                null,
                DashboardPaymentPresenter::money($unpaidAmount),
                $fineCurrencyCode
            );
            if ($fineCurrency->count() > 1) {
                $financial['currency_note'] = 'Fine amounts are domain totals; currencies are not mixed into a single figure.';
            }
        }

        if ($visibility['employees']) {
            $activeEmployees = User::query()
                ->whereIn('user_type', [UserType::Employee, UserType::Admin])
                ->where('is_active', true)
                ->count();
            $summary['employees'] = [
                'active' => $activeEmployees,
            ];
            $kpis['employees_total'] = $activeEmployees;
            $sections['employees'] = true;
        }

        $periodDays = (int) $context['date_from']->startOfDay()->diffInDays($context['date_to']->startOfDay()) + 1;
        $summary['operational_performance'] = [
            'period_days' => $periodDays,
            'applications_per_day' => $visibility['applications']
                ? round(($summary['applications']['submitted'] ?? 0) / max(1, $periodDays), 2)
                : null,
        ];
        $sections['operational_performance'] = true;

        foreach ($kpis as $key => $value) {
            $summary[$key] = $value;
        }

        $visibilityPayload = array_merge($visibility, [
            'sections' => $sections,
            'financial' => $visibility['payments'],
            'overview' => true,
        ]);

        return ReportResponse::build($context, [
            'summary' => $summary,
            'kpis' => $kpis,
            'series' => ReportContract::namedSeries($seriesNamed),
            'breakdowns' => ReportContract::aliasBreakdowns($breakdowns),
            'operational' => $operational,
            'financial' => $financial,
            'rows' => [],
            'pagination' => null,
            'visibility' => $visibilityPayload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, int>
     */
    private function bucketCounts(Builder $query, string $column, array $context): array
    {
        $expr = $this->periods->bucketExpression($column, $context['group_by']);

        return $query
            ->selectRaw("{$expr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();
    }
}
