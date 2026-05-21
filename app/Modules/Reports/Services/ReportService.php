<?php

namespace App\Modules\Reports\Services;

use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Models\AppointmentSlot;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\TestAppointment;
use App\Models\TestResult;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
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

        $paymentStats = Payment::query()
            ->where('status', PaymentStatus::Completed)
            ->selectRaw('count(*) as total_count, coalesce(sum(amount), 0) as total_amount')
            ->first();

        $unpaidFines = Fine::query()
            ->where('status', FineStatus::Unpaid)
            ->selectRaw('count(*) as total_count, coalesce(sum(amount), 0) as total_amount')
            ->first();

        return [
            'applications' => [
                'total' => LicenseApplication::query()->count(),
                'by_status' => $applicationsByStatus,
            ],
            'licenses' => [
                'total_issued' => License::query()->count(),
            ],
            'payments' => [
                'completed_count' => (int) ($paymentStats->total_count ?? 0),
                'completed_amount' => (float) ($paymentStats->total_amount ?? 0),
            ],
            'fines' => [
                'unpaid_count' => (int) ($unpaidFines->total_count ?? 0),
                'unpaid_amount' => (float) ($unpaidFines->total_amount ?? 0),
            ],
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
        ];
    }
}
