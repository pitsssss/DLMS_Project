<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\AppointmentStatus;
use App\Exceptions\ApiException;
use App\Models\AppointmentCenter;
use App\Models\AppointmentSlot;
use App\Models\AuditLog;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Appointments\Support\AppointmentSlotPresenter;
use App\Modules\Appointments\Support\SlotIdentity;
use App\Services\AuditLogService;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardAppointmentSlotService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly AppointmentSlotPresenter $presenter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $this->filteredQuery($filters)
            ->with([
                'testType:id,code,name',
                'appointmentCenter:id,name,address,is_active',
            ])
            ->orderBy('date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function stats(array $filters = []): array
    {
        $base = $this->filteredQuery($filters);
        $today = $this->presenter->businessToday();

        $totalSlots = (clone $base)->count();
        $activeSlots = (clone $base)->where('is_active', true)->count();
        $inactiveSlots = $totalSlots - $activeSlots;
        $upcomingSlots = (clone $base)->where('date', '>=', $today)->count();
        $pastSlots = (clone $base)->where('date', '<', $today)->count();

        $availableSlots = (clone $base)
            ->where('is_active', true)
            ->where('date', '>=', $today)
            ->whereColumn('booked_count', '<', 'capacity')
            ->count();

        $fullSlots = (clone $base)
            ->where('is_active', true)
            ->where('date', '>=', $today)
            ->whereColumn('booked_count', '>=', 'capacity')
            ->count();

        $aggregates = (clone $base)
            ->selectRaw('COALESCE(SUM(capacity), 0) as total_capacity')
            ->selectRaw('COALESCE(SUM(booked_count), 0) as total_booked')
            ->first();

        $totalCapacity = (int) ($aggregates->total_capacity ?? 0);
        $totalBooked = (int) ($aggregates->total_booked ?? 0);
        $remainingCapacity = max(0, $totalCapacity - $totalBooked);
        $utilizationRate = $totalCapacity > 0
            ? round(($totalBooked / $totalCapacity) * 100, 2)
            : null;

        $slotsToday = (clone $base)->where('date', $today)->count();

        $bookingsToday = TestAppointment::query()
            ->whereIn('appointment_slot_id', (clone $base)->where('date', $today)->select('id'))
            ->where('status', AppointmentStatus::Booked)
            ->count();

        return [
            'total_slots' => $totalSlots,
            'active_slots' => $activeSlots,
            'inactive_slots' => $inactiveSlots,
            'upcoming_slots' => $upcomingSlots,
            'past_slots' => $pastSlots,
            'available_slots' => $availableSlots,
            'full_slots' => $fullSlots,
            'total_capacity' => $totalCapacity,
            'total_booked' => $totalBooked,
            'remaining_capacity' => $remainingCapacity,
            'utilization_rate' => $utilizationRate,
            'slots_today' => $slotsToday,
            'bookings_today' => $bookingsToday,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $testTypes = TestType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (TestType $type) => [
                'value' => $type->id,
                'label' => EmployeeMessageTranslator::get('employee.test_types.'.$type->code),
            ])
            ->values()
            ->all();

        $testTypeCodes = TestType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (TestType $type) => [
                'value' => $type->code,
                'label' => EmployeeMessageTranslator::get('employee.test_types.'.$type->code),
            ])
            ->values()
            ->all();

        $appointmentCenters = AppointmentCenter::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AppointmentCenter $center) => [
                'value' => $center->id,
                'label' => $center->name,
            ])
            ->values()
            ->all();

        $activeStates = [
            ['value' => 'true', 'label' => Msg::get('appointment_slots.statuses.active')],
            ['value' => 'false', 'label' => Msg::get('appointment_slots.statuses.inactive')],
        ];

        $availabilityStates = [
            ['value' => 'available', 'label' => Msg::get('appointment_slots.availability.available')],
            ['value' => 'full', 'label' => Msg::get('appointment_slots.availability.full')],
            ['value' => 'inactive', 'label' => Msg::get('appointment_slots.availability.inactive')],
            ['value' => 'past', 'label' => Msg::get('appointment_slots.availability.past')],
            ['value' => 'upcoming', 'label' => Msg::get('appointment_slots.availability.upcoming')],
        ];

        return [
            'test_types' => $testTypes,
            'test_type_codes' => $testTypeCodes,
            'appointment_centers' => $appointmentCenters,
            'active_states' => $activeStates,
            'availability_states' => $availabilityStates,
            'per_page' => [
                ['value' => 10, 'label' => '10'],
                ['value' => 20, 'label' => '20'],
                ['value' => 25, 'label' => '25'],
                ['value' => 50, 'label' => '50'],
            ],
        ];
    }

    public function get(int $slotId): AppointmentSlot
    {
        $slot = AppointmentSlot::query()
            ->with([
                'testType:id,code,name',
                'appointmentCenter:id,name,address,is_active',
                'createdBy:id,name',
                'updatedBy:id,name',
                'deactivatedBy:id,name',
            ])
            ->withCount([
                'testAppointments as bookings_total_count',
                'testAppointments as bookings_booked_count' => fn (Builder $q) => $q->where('status', AppointmentStatus::Booked),
                'testAppointments as bookings_completed_count' => fn (Builder $q) => $q->where('status', AppointmentStatus::Completed),
                'testAppointments as bookings_cancelled_count' => fn (Builder $q) => $q->where('status', AppointmentStatus::Cancelled),
                'testAppointments as bookings_no_show_count' => fn (Builder $q) => $q->where('status', AppointmentStatus::NoShow),
            ])
            ->find($slotId);

        if ($slot === null) {
            throw new ApiException('messages.appointment_slots.not_found', 404);
        }

        return $slot;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, Request $request): AppointmentSlot
    {
        $testType = $this->requireActiveTestType((int) $data['test_type_id']);
        $centerId = SlotIdentity::normalizedCenterId(
            array_key_exists('appointment_center_id', $data) ? (int) $data['appointment_center_id'] : null
        );

        if ($centerId !== null) {
            $this->requireActiveCenter($centerId);
        }

        $date = (string) $data['date'];
        $startTime = SlotIdentity::normalizeTime((string) $data['start_time']);
        $endTime = SlotIdentity::normalizeTime((string) $data['end_time']);

        $this->assertDateNotPast($date);
        $this->assertEndAfterStart($startTime, $endTime);

        $identityKey = SlotIdentity::buildKey($testType->id, $centerId, $date, $startTime, $endTime);

        if (AppointmentSlot::query()->where('identity_key', $identityKey)->exists()) {
            throw new ApiException('messages.appointment_slots.duplicate_identity', 422);
        }

        return DB::transaction(function () use ($data, $actor, $request, $testType, $centerId, $date, $startTime, $endTime, $identityKey): AppointmentSlot {
            $slot = AppointmentSlot::query()->create([
                'test_type_id' => $testType->id,
                'appointment_center_id' => $centerId,
                'identity_key' => $identityKey,
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'capacity' => (int) $data['capacity'],
                'booked_count' => 0,
                'location' => isset($data['location']) ? trim((string) $data['location']) : null,
                'is_active' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'version' => 1,
            ]);

            $this->auditLogs->log(
                $actor,
                'appointment_slot.created',
                'appointment_slot',
                $slot->id,
                null,
                $this->auditValues($slot, $data['reason'] ?? null),
                $request,
            );

            return $slot->fresh([
                'testType:id,code,name',
                'appointmentCenter:id,name,address,is_active',
                'createdBy:id,name',
                'updatedBy:id,name',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AppointmentSlot $slot, array $data, User $actor, Request $request): AppointmentSlot
    {
        return DB::transaction(function () use ($slot, $data, $actor, $request): AppointmentSlot {
            $locked = AppointmentSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            $expectedVersion = (int) $data['version'];
            if ((int) $locked->version !== $expectedVersion) {
                throw new ApiException('messages.appointment_slots.stale_version', 409);
            }

            $old = $this->auditSnapshot($locked);
            $hasActiveBookings = $locked->hasActiveBookings();

            if ($hasActiveBookings) {
                foreach (['test_type_id', 'appointment_center_id', 'date', 'start_time', 'end_time'] as $immutableField) {
                    if (array_key_exists($immutableField, $data)) {
                        throw new ApiException('messages.appointment_slots.identity_immutable_with_bookings', 422);
                    }
                }
            } else {
                $identityChanging = array_key_exists('test_type_id', $data)
                    || array_key_exists('appointment_center_id', $data)
                    || array_key_exists('date', $data)
                    || array_key_exists('start_time', $data)
                    || array_key_exists('end_time', $data);

                if ($identityChanging) {
                    if (array_key_exists('test_type_id', $data)) {
                        $this->requireActiveTestType((int) $data['test_type_id']);
                        $locked->test_type_id = (int) $data['test_type_id'];
                    }

                    if (array_key_exists('appointment_center_id', $data)) {
                        $centerId = SlotIdentity::normalizedCenterId(
                            $data['appointment_center_id'] !== null ? (int) $data['appointment_center_id'] : null
                        );
                        if ($centerId !== null) {
                            $this->requireActiveCenter($centerId);
                        }
                        $locked->appointment_center_id = $centerId;
                    }

                    if (array_key_exists('date', $data)) {
                        $this->assertDateNotPast((string) $data['date']);
                        $locked->date = (string) $data['date'];
                    }

                    if (array_key_exists('start_time', $data)) {
                        $locked->start_time = SlotIdentity::normalizeTime((string) $data['start_time']);
                    }

                    if (array_key_exists('end_time', $data)) {
                        $locked->end_time = SlotIdentity::normalizeTime((string) $data['end_time']);
                    }

                    $this->assertEndAfterStart((string) $locked->start_time, (string) $locked->end_time);

                    $identityKey = SlotIdentity::keyForSlot($locked);
                    if (AppointmentSlot::query()->where('identity_key', $identityKey)->where('id', '!=', $locked->id)->exists()) {
                        throw new ApiException('messages.appointment_slots.duplicate_identity', 422);
                    }

                    $locked->identity_key = $identityKey;
                }
            }

            if (array_key_exists('capacity', $data)) {
                $newCapacity = (int) $data['capacity'];
                if ($newCapacity < (int) $locked->booked_count) {
                    throw new ApiException('messages.appointment_slots.unsafe_capacity_reduction', 422);
                }
                $locked->capacity = $newCapacity;
            }

            if (array_key_exists('location', $data)) {
                $locked->location = $data['location'] !== null ? trim((string) $data['location']) : null;
            }

            $locked->updated_by = $actor->id;
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $fresh = $locked->fresh();
            $this->auditLogs->log(
                $actor,
                'appointment_slot.updated',
                'appointment_slot',
                $locked->id,
                $old,
                $this->auditValues($fresh, $data['reason'] ?? null),
                $request,
            );

            return $fresh->load([
                'testType:id,code,name',
                'appointmentCenter:id,name,address,is_active',
                'createdBy:id,name',
                'updatedBy:id,name',
                'deactivatedBy:id,name',
            ]);
        });
    }

    public function activate(AppointmentSlot $slot, User $actor, Request $request, ?string $reason = null): AppointmentSlot
    {
        if ($slot->is_active) {
            return $slot;
        }

        if ($this->presenter->isPast($slot)) {
            throw new ApiException('messages.appointment_slots.past_slot_rejected', 422);
        }

        return DB::transaction(function () use ($slot, $actor, $request, $reason): AppointmentSlot {
            $locked = AppointmentSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            $identityKey = SlotIdentity::keyForSlot($locked);
            if (AppointmentSlot::query()
                ->where('identity_key', $identityKey)
                ->where('id', '!=', $locked->id)
                ->where('is_active', true)
                ->exists()) {
                throw new ApiException('messages.appointment_slots.duplicate_active_identity', 422);
            }

            $old = ['is_active' => (bool) $locked->is_active];
            $locked->is_active = true;
            $locked->deactivated_at = null;
            $locked->deactivated_by = null;
            $locked->updated_by = $actor->id;
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'appointment_slot.activated',
                'appointment_slot',
                $locked->id,
                $old,
                $this->auditValues($locked, $reason, ['is_active' => true]),
                $request,
            );

            return $locked->fresh([
                'testType:id,code,name',
                'appointmentCenter:id,name,address,is_active',
            ]);
        });
    }

    public function deactivate(AppointmentSlot $slot, User $actor, Request $request, string $reason): AppointmentSlot
    {
        if (! $slot->is_active) {
            return $slot;
        }

        $this->assertCanDeactivate($slot);

        return DB::transaction(function () use ($slot, $actor, $request, $reason): AppointmentSlot {
            $locked = AppointmentSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            $old = ['is_active' => (bool) $locked->is_active];
            $locked->is_active = false;
            $locked->deactivated_at = now();
            $locked->deactivated_by = $actor->id;
            $locked->updated_by = $actor->id;
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'appointment_slot.deactivated',
                'appointment_slot',
                $locked->id,
                $old,
                $this->auditValues($locked, $reason, [
                    'is_active' => false,
                    'deactivated_at' => $locked->deactivated_at?->toIso8601String(),
                ]),
                $request,
            );

            return $locked->fresh([
                'testType:id,code,name',
                'appointmentCenter:id,name,address,is_active',
                'deactivatedBy:id,name',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateBookings(AppointmentSlot $slot, array $filters, User $actor): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        $query = TestAppointment::query()
            ->where('appointment_slot_id', $slot->id)
            ->with([
                'application:id,application_number,citizen_id,license_type_id,service_type_id',
                'citizen:id,name',
                'testType:id,code,name',
                'testResult:id,test_appointment_id,result',
            ])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $search = $filters['search'] ?? null;
        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereHas('application', fn (Builder $q) => $q->where('application_number', 'like', $like))
                    ->orWhereHas('citizen', fn (Builder $q) => $q->where('name', 'like', $like));
            });
        }

        return $query->paginate($perPage);
    }

    public function paginateAuditLogs(AppointmentSlot $slot, int $perPage): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', 'appointment_slot')
            ->where('entity_id', $slot->id)
            ->whereIn('action', [
                'appointment_slot.created',
                'appointment_slot.updated',
                'appointment_slot.activated',
                'appointment_slot.deactivated',
            ])
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min($perPage, 100));
    }

    /**
     * @return array<string, mixed>
     */
    public function transformAuditItem(AuditLog $log): array
    {
        $old = is_array($log->old_values) ? $log->old_values : [];
        $new = is_array($log->new_values) ? $log->new_values : [];

        return [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => Msg::get('appointment_slots.audit_actions.'.$log->action),
            'performed_by' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'reason' => $new['reason'] ?? $old['reason'] ?? null,
            'changes' => [
                'old' => $old,
                'new' => $new,
            ],
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    public function assertCanDeactivate(AppointmentSlot $slot): void
    {
        if ($slot->hasActiveBookings()) {
            throw new ApiException('messages.appointment_slots.unsafe_deactivation', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AppointmentSlot>
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = AppointmentSlot::query();
        $today = $this->presenter->businessToday();

        if (! empty($filters['search'])) {
            $like = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('location', 'like', $like)
                    ->orWhereHas('appointmentCenter', fn (Builder $q) => $q->where('name', 'like', $like))
                    ->orWhereHas('testType', fn (Builder $q) => $q
                        ->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like));
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('date', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['test_type_id'])) {
            $query->where('test_type_id', (int) $filters['test_type_id']);
        }

        if (! empty($filters['test_type_code'])) {
            $code = (string) $filters['test_type_code'];
            $query->whereHas('testType', fn (Builder $q) => $q->where('code', $code));
        }

        if (array_key_exists('appointment_center_id', $filters) && $filters['appointment_center_id'] !== null) {
            $query->where('appointment_center_id', (int) $filters['appointment_center_id']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['availability'])) {
            match ((string) $filters['availability']) {
                'past' => $query->where('date', '<', $today),
                'inactive' => $query->where('is_active', false),
                'full' => $query->where('is_active', true)
                    ->where('date', '>=', $today)
                    ->whereColumn('booked_count', '>=', 'capacity'),
                'available' => $query->where('is_active', true)
                    ->where('date', '>=', $today)
                    ->whereColumn('booked_count', '<', 'capacity'),
                'upcoming' => $query->where('date', '>=', $today),
                default => null,
            };
        }

        return $query;
    }

    private function requireActiveTestType(int $testTypeId): TestType
    {
        $testType = TestType::query()->whereKey($testTypeId)->where('is_active', true)->first();
        if ($testType === null) {
            throw new ApiException('messages.appointment_slots.test_type_invalid', 422);
        }

        return $testType;
    }

    private function requireActiveCenter(int $centerId): AppointmentCenter
    {
        $center = AppointmentCenter::query()->whereKey($centerId)->where('is_active', true)->first();
        if ($center === null) {
            throw new ApiException('messages.appointment_slots.center_invalid', 422);
        }

        return $center;
    }

    private function assertDateNotPast(string $date): void
    {
        if ($date < $this->presenter->businessToday()) {
            throw new ApiException('messages.appointment_slots.past_date_rejected', 422);
        }
    }

    private function assertEndAfterStart(string $startTime, string $endTime): void
    {
        if ($endTime <= $startTime) {
            throw new ApiException('messages.appointment_slots.end_time_after_start', 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(AppointmentSlot $slot): array
    {
        return [
            'test_type_id' => $slot->test_type_id,
            'appointment_center_id' => $slot->appointment_center_id,
            'date' => $slot->date->format('Y-m-d'),
            'start_time' => (string) $slot->start_time,
            'end_time' => (string) $slot->end_time,
            'capacity' => (int) $slot->capacity,
            'booked_count' => (int) $slot->booked_count,
            'location' => $slot->location,
            'is_active' => (bool) $slot->is_active,
            'version' => (int) $slot->version,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function auditValues(AppointmentSlot $slot, ?string $reason = null, array $extra = []): array
    {
        $values = array_merge($this->auditSnapshot($slot), $extra);

        if ($reason !== null && trim($reason) !== '') {
            $values['reason'] = trim($reason);
        }

        return $values;
    }
}
