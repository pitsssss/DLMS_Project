<?php

namespace App\Modules\Reports\Services;

use App\Enums\FineStatus;
use App\Enums\TestResultStatus;
use App\Models\AppointmentSlot;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\User;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Dashboard\Support\Reports\ReportPaymentMetrics;
use App\Modules\Dashboard\Support\Reports\ReportVisibility;
use Illuminate\Support\Facades\DB;

/**
 * Legacy all-time reports overview.
 *
 * @deprecated Use /api/dashboard/reports/* for filtered, permission-aware reporting.
 */
class ReportService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(?User $viewer = null): array
    {
        $visibility = $viewer ? ReportVisibility::for($viewer) : null;

        $applicationsByStatus = LicenseApplication::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count, $status) => [
                'status' => $status,
                'total' => (int) $count,
            ])
            ->values()
            ->all();

        $testStats = TestResult::query()
            ->select('result', DB::raw('count(*) as total'))
            ->groupBy('result')
            ->pluck('total', 'result')
            ->map(fn ($count, $result) => [
                'result' => $result,
                'total' => (int) $count,
            ])
            ->values()
            ->all();

        $payments = null;
        if ($visibility === null || $visibility['payments']) {
            $metrics = ReportPaymentMetrics::applicationPayments();
            $payments = [
                'completed_count' => $metrics['completed_count'],
                'completed_amount' => $metrics['completed_amount'],
                'completed_amount_by_currency' => $metrics['completed_amount_by_currency'],
                'currency' => $metrics['currency'],
            ];
        }

        $fines = null;
        if ($visibility === null || $visibility['fines']) {
            $unpaidFines = Fine::query()
                ->where('status', FineStatus::Unpaid)
                ->selectRaw('count(*) as total_count, coalesce(sum(amount), 0) as total_amount')
                ->first();

            $fines = [
                'unpaid_count' => (int) ($unpaidFines->total_count ?? 0),
                'unpaid_amount' => DashboardPaymentPresenter::money($unpaidFines->total_amount ?? 0),
            ];
        }

        return [
            'applications' => [
                'total' => LicenseApplication::query()->count(),
                'by_status' => $applicationsByStatus,
            ],
            'licenses' => [
                'total_issued' => License::query()->count(),
            ],
            'payments' => $payments,
            'fines' => $fines,
            'appointments' => [
                'total_booked' => TestAppointment::query()->count(),
                'active_slots' => AppointmentSlot::query()->where('is_active', true)->count(),
            ],
            'tests' => [
                'results_by_status' => $testStats,
                'passed' => TestResult::query()->where('result', TestResultStatus::Passed)->count(),
                'failed' => TestResult::query()->where('result', TestResultStatus::Failed)->count(),
                'no_show' => TestResult::query()->where('result', TestResultStatus::NoShow)->count(),
            ],
            'generated_at' => now()->toIso8601String(),
            'deprecated' => true,
            'replacement' => '/api/dashboard/reports/summary',
        ];
    }
}
