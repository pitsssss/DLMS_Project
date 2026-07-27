<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\AppointmentStatus;
use App\Models\TestAppointment;
use App\Modules\Dashboard\Support\Reports\ReportPeriodResolver;
use App\Modules\Dashboard\Support\Reports\ReportResponse;
use App\Modules\Dashboard\Support\Reports\ReportSeriesBuilder;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardReportAppointmentsService
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
        $booked = (clone $base)->where('status', AppointmentStatus::Booked)->count();
        $completed = (clone $base)->where('status', AppointmentStatus::Completed)->count();
        $cancelled = (clone $base)->where('status', AppointmentStatus::Cancelled)->count();
        $noShow = (clone $base)->where('status', AppointmentStatus::NoShow)->count();
        $upcoming = (clone $base)
            ->where('status', AppointmentStatus::Booked)
            ->where('scheduled_at', '>=', $this->clock->now()->utc())
            ->count();

        $utilization = $this->utilizationRate($context, $filters);

        $bucketExpr = $this->periods->bucketExpression('scheduled_at', $context['group_by']);
        $scheduledRows = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();

        $completedRows = (clone $base)
            ->where('status', AppointmentStatus::Completed)
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();

        $noShowRows = (clone $base)
            ->where('status', AppointmentStatus::NoShow)
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as aggregate_count")
            ->groupBy('bucket')
            ->pluck('aggregate_count', 'bucket')
            ->map(fn ($c) => (int) $c)
            ->all();

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status instanceof AppointmentStatus ? $row->status->value : (string) $row->status,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();

        $byTestType = (clone $base)
            ->join('test_types', 'test_types.id', '=', 'test_appointments.test_type_id')
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

        $byLocation = (clone $base)
            ->join('appointment_slots', 'appointment_slots.id', '=', 'test_appointments.appointment_slot_id')
            ->whereNotNull('appointment_slots.location')
            ->select('appointment_slots.location', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('appointment_slots.location')
            ->orderByDesc('aggregate_count')
            ->get()
            ->map(fn ($row) => [
                'location' => (string) $row->location,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = (clone $base)
            ->with([
                'application:id,application_number',
                'citizen:id,name',
                'testType:id,code,name',
                'testResult:id,test_appointment_id,result',
            ])
            ->orderByDesc('scheduled_at')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function (TestAppointment $appointment) {
            return [
                'id' => $appointment->id,
                'application_number' => $appointment->application?->application_number,
                'citizen' => $appointment->citizen
                    ? ['id' => $appointment->citizen->id, 'name' => $appointment->citizen->name]
                    : null,
                'test_type' => $appointment->testType
                    ? ['code' => $appointment->testType->code, 'name' => $appointment->testType->name]
                    : null,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'status' => $appointment->status->value,
                'test_result' => $appointment->testResult?->result?->value,
            ];
        })->values()->all();

        return ReportResponse::build($context, [
            'summary' => [
                'total' => $total,
                'booked' => $booked,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'upcoming' => $upcoming,
                'utilization_rate' => $utilization['rate'],
                'utilization_note' => $utilization['note'],
            ],
            'series' => [
                ['key' => 'scheduled', 'items' => ReportSeriesBuilder::fill($context, $scheduledRows, 'count')],
                ['key' => 'completed', 'items' => ReportSeriesBuilder::fill($context, $completedRows, 'count')],
                ['key' => 'no_show', 'items' => ReportSeriesBuilder::fill($context, $noShowRows, 'count')],
            ],
            'breakdowns' => [
                'by_status' => $byStatus,
                'by_test_type' => $byTestType,
                'by_location' => $byLocation,
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
     * @return array{rate: ?float, note: ?string}
     */
    private function utilizationRate(array $context, array $filters): array
    {
        $slotQuery = DB::table('appointment_slots')
            ->where('is_active', true)
            ->where('capacity', '>', 0);

        if (! empty($filters['test_type_code'])) {
            $slotQuery->join('test_types', 'test_types.id', '=', 'appointment_slots.test_type_id')
                ->where('test_types.code', $filters['test_type_code']);
        }

        $totals = $slotQuery
            ->selectRaw('COALESCE(SUM(capacity), 0) as total_capacity, COALESCE(SUM(booked_count), 0) as total_booked')
            ->first();

        $capacity = (int) ($totals->total_capacity ?? 0);
        if ($capacity === 0) {
            return [
                'rate' => null,
                'note' => 'Slot capacity data is unavailable for the selected filters.',
            ];
        }

        return [
            'rate' => ReportResponse::rate((int) ($totals->total_booked ?? 0), $capacity),
            'note' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(array $context, array $filters): Builder
    {
        $query = TestAppointment::query();
        $this->clock->applyUtcRange($query, 'scheduled_at', $context['query_from'], $context['query_to_exclusive']);

        if (! empty($filters['appointment_status'])) {
            $query->where('status', $filters['appointment_status']);
        }
        if (! empty($filters['test_type_code'])) {
            $query->whereHas('testType', fn (Builder $q) => $q->where('code', $filters['test_type_code']));
        }

        return $query;
    }
}
