<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\AppointmentStatus;
use App\Enums\TestResultStatus;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Modules\Dashboard\Requests\ListDashboardTestAppointmentsRequest;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardTestAppointmentService
{
    public function __construct(
        private readonly BusinessClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TestAppointment>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = TestAppointment::query()
            ->with([
                'application:id,application_number,status,citizen_id',
                'citizen:id,name',
                'testType:id,code,name',
                'testResult:id,test_appointment_id,result',
                'appointmentSlot:id,test_type_id,appointment_center_id,date,start_time,end_time,location',
                'appointmentSlot.appointmentCenter:id,name,address',
            ])
            ->orderBy('scheduled_at')
            ->orderBy('id');

        $this->applyStatusFilter($query, (string) ($filters['status'] ?? ListDashboardTestAppointmentsRequest::STATUS_WAITING_RESULT));
        $this->applyTestTypeFilter($query, $filters);
        $this->applyDateFilters($query, $filters);
        $this->applySearchFilter($query, $filters['search'] ?? null);

        $paginator = $query->paginate($perPage);
        $this->attachAttemptStats(collect($paginator->items()));

        return $paginator;
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status === ListDashboardTestAppointmentsRequest::STATUS_WAITING_RESULT) {
            $query->where('status', AppointmentStatus::Booked)
                ->whereDoesntHave('testResult');

            return;
        }

        $query->where('status', $status);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyTestTypeFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['test_type_id'])) {
            $query->where('test_type_id', (int) $filters['test_type_id']);
        }

        if (! empty($filters['test_type_code'])) {
            $code = (string) $filters['test_type_code'];
            $query->whereHas('testType', fn (Builder $q) => $q->where('code', $code));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        $tz = $this->clock->timezone();

        if (! empty($filters['date'])) {
            $day = CarbonImmutable::parse((string) $filters['date'], $tz)->startOfDay();
            $this->clock->applyUtcRange(
                $query,
                'scheduled_at',
                $this->clock->toUtc($day),
                $this->clock->toUtc($day->addDay())
            );

            return;
        }

        if (! empty($filters['date_from'])) {
            $from = CarbonImmutable::parse((string) $filters['date_from'], $tz)->startOfDay();
            $query->where('scheduled_at', '>=', $this->clock->toUtc($from));
        }

        if (! empty($filters['date_to'])) {
            $toExclusive = CarbonImmutable::parse((string) $filters['date_to'], $tz)->startOfDay()->addDay();
            $query->where('scheduled_at', '<', $this->clock->toUtc($toExclusive));
        }
    }

    private function applySearchFilter(Builder $query, mixed $search): void
    {
        if (! is_string($search) || $search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function (Builder $inner) use ($like): void {
            $inner->whereHas('application', fn (Builder $q) => $q->where('application_number', 'like', $like))
                ->orWhereHas('citizen', fn (Builder $q) => $q->where('name', 'like', $like));
        });
    }

    /**
     * @param  Collection<int, TestAppointment>  $appointments
     */
    private function attachAttemptStats(Collection $appointments): void
    {
        if ($appointments->isEmpty()) {
            return;
        }

        $applicationIds = $appointments->pluck('application_id')->filter()->unique()->values();

        $grouped = TestResult::query()
            ->whereIn('application_id', $applicationIds)
            ->get(['application_id', 'test_type_id', 'result'])
            ->groupBy(fn (TestResult $result): string => $result->application_id.'-'.$result->test_type_id);

        foreach ($appointments as $appointment) {
            $rows = $grouped->get($appointment->application_id.'-'.$appointment->test_type_id, collect());
            $previous = $rows->filter(function (TestResult $result): bool {
                $value = $result->result instanceof TestResultStatus
                    ? $result->result
                    : TestResultStatus::tryFrom((string) $result->result);

                return in_array($value, [TestResultStatus::Failed, TestResultStatus::NoShow], true);
            })->count();

            $appointment->setAttribute('previous_attempts_count', $previous);
            $appointment->setAttribute('next_attempt_number', $rows->count() + 1);
        }
    }
}
