<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\User;
use App\Models\Fine;
use App\Models\License;
use App\Modules\Dashboard\Support\Reports\ReportPaymentMetrics;
use App\Modules\Dashboard\Support\Reports\ReportPeriodResolver;
use App\Modules\Dashboard\Support\Reports\ReportResponse;
use App\Modules\Dashboard\Support\Reports\ReportVisibility;
use App\Modules\Licenses\Services\LicenseIssuanceEligibilityService;
use App\Support\BusinessClock;

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

        if ($visibility['applications']) {
            $appQuery = LicenseApplication::query();
            $this->clock->applyUtcRange($appQuery, 'created_at', $context['query_from'], $context['query_to_exclusive']);
            $submitted = (clone $appQuery)->count();
            $approved = (clone $appQuery)->where('status', ApplicationStatus::Approved)->count();
            $rejected = (clone $appQuery)->where('status', ApplicationStatus::Rejected)->count();
            $pending = (clone $appQuery)->whereIn('status', ApplicationStatus::activeValues())->count();

            $summary['applications'] = [
                'submitted' => $submitted,
                'approved' => $approved,
                'rejected' => $rejected,
                'pending_in_progress' => $pending,
                'approval_rate' => ReportResponse::rate($approved, $approved + $rejected),
            ];
            $sections['applications'] = true;
        }

        if ($visibility['citizens']) {
            $citizenQuery = User::query()->where('user_type', UserType::Citizen);
            $this->clock->applyUtcRange($citizenQuery, 'created_at', $context['query_from'], $context['query_to_exclusive']);
            $summary['citizens'] = [
                'registered' => (clone $citizenQuery)->count(),
                'active' => User::query()->where('user_type', UserType::Citizen)->where('is_active', true)->count(),
            ];
            $sections['citizens'] = true;
        }

        if ($visibility['document_reviews']) {
            $docQuery = ApplicationDocument::query()->where('status', DocumentStatus::PendingReview);
            $this->clock->applyUtcRange($docQuery, 'created_at', $context['query_from'], $context['query_to_exclusive']);
            $reviewedQuery = ApplicationDocument::query()
                ->whereIn('status', [DocumentStatus::Approved, DocumentStatus::Rejected])
                ->whereNotNull('reviewed_at');
            $this->clock->applyUtcRange($reviewedQuery, 'reviewed_at', $context['query_from'], $context['query_to_exclusive']);

            $summary['document_reviews'] = [
                'pending_review' => (clone $docQuery)->count(),
                'reviewed_in_period' => (clone $reviewedQuery)->count(),
            ];
            $sections['document_reviews'] = true;
        }

        if ($visibility['tests']) {
            $testQuery = TestResult::query();
            $this->clock->applyUtcRange($testQuery, 'recorded_at', $context['query_from'], $context['query_to_exclusive']);
            $total = (clone $testQuery)->count();
            $passed = (clone $testQuery)->where('result', TestResultStatus::Passed)->count();

            $summary['tests'] = [
                'recorded' => $total,
                'passed' => $passed,
                'pass_rate' => ReportResponse::rate($passed, $total),
            ];
            $sections['tests'] = true;
        }

        if ($visibility['appointments']) {
            $apptQuery = TestAppointment::query();
            $this->clock->applyUtcRange($apptQuery, 'scheduled_at', $context['query_from'], $context['query_to_exclusive']);
            $summary['appointments'] = [
                'scheduled' => (clone $apptQuery)->count(),
                'completed' => (clone $apptQuery)->where('status', AppointmentStatus::Completed)->count(),
                'no_show' => (clone $apptQuery)->where('status', AppointmentStatus::NoShow)->count(),
            ];
            $sections['appointments'] = true;
        }

        if ($visibility['licenses']) {
            $licenseQuery = License::query()
                ->where('issue_date', '>=', $context['date_from']->toDateString())
                ->where('issue_date', '<=', $context['date_to']->toDateString());
            $summary['licenses'] = [
                'issued_in_period' => (clone $licenseQuery)->count(),
                'ready_for_issuance' => $this->issuanceEligibility->readyCount(),
            ];
            $sections['licenses'] = true;
        }

        if ($visibility['payments']) {
            $range = fn ($q) => $this->clock->applyUtcRange(
                $q,
                'created_at',
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
            $sections['payments'] = true;
        }

        if ($visibility['fines']) {
            $fineQuery = Fine::query();
            $this->clock->applyUtcRange($fineQuery, 'created_at', $context['query_from'], $context['query_to_exclusive']);
            $unpaidAmount = (clone $fineQuery)->where('status', FineStatus::Unpaid)->sum('amount');

            $summary['fines'] = [
                'total_in_period' => (clone $fineQuery)->count(),
                'unpaid' => Fine::query()->where('status', FineStatus::Unpaid)->count(),
                'unpaid_amount' => \App\Modules\Dashboard\Support\DashboardPaymentPresenter::money($unpaidAmount),
            ];
            $sections['fines'] = true;
        }

        if ($visibility['employees']) {
            $summary['employees'] = [
                'active' => User::query()
                    ->whereIn('user_type', [UserType::Employee, UserType::Admin])
                    ->where('is_active', true)
                    ->count(),
            ];
            $sections['employees'] = true;
        }

        $summary['operational_performance'] = [
            'period_days' => (int) $context['date_from']->startOfDay()->diffInDays($context['date_to']->startOfDay()) + 1,
            'applications_per_day' => $visibility['applications']
                ? round(($summary['applications']['submitted'] ?? 0) / max(1, (int) $context['date_from']->diffInDays($context['date_to']) + 1), 2)
                : null,
        ];
        $sections['operational_performance'] = true;

        return ReportResponse::build($context, [
            'summary' => $summary,
            'series' => [],
            'breakdowns' => [],
            'rows' => [],
            'pagination' => null,
            'visibility' => array_merge(ReportVisibility::for($user), ['sections' => $sections]),
        ]);
    }
}
