<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\Auth\Repositories\AuthRepository;
use App\Modules\Notifications\Services\NotificationService;
use App\Services\AuditLogService;
use App\Support\EmployeeMessageTranslator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DashboardCitizenService
{
    public function __construct(
        private readonly AuthRepository $users,
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = User::query()
            ->where('user_type', UserType::Citizen)
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'LIKE', '%' . $term . '%')
                  ->orWhere('email', 'LIKE', '%' . $term . '%')
                  ->orWhere('phone', 'LIKE', '%' . $term . '%')
                  ->orWhere('national_id', 'LIKE', '%' . $term . '%');
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['profile_status'])) {
            $query->where('profile_status', $filters['profile_status'] instanceof ProfileStatus
                ? $filters['profile_status']->value
                : trim((string) $filters['profile_status']));
        }

        return $query->paginate($perPage);
    }

    public function stats(): array
    {
        $base = User::query()->where('user_type', UserType::Citizen);

        $total    = (clone $base)->count();
        $active   = (clone $base)->where('is_active', true)->count();
        $inactive = (clone $base)->where('is_active', false)->count();

        $profileCounts = (clone $base)
            ->selectRaw('profile_status, COUNT(*) as cnt')
            ->groupBy('profile_status')
            ->pluck('cnt', 'profile_status');

        $profileStatuses = [];
        foreach (ProfileStatus::cases() as $status) {
            $profileStatuses[$status->value] = (int) ($profileCounts[$status->value] ?? 0);
        }

        return compact('total', 'active', 'inactive', 'profileStatuses');
    }

    public function profileStatuses(): array
    {
        return array_map(
            fn (ProfileStatus $status) => [
                'value' => $status->value,
                'label' => EmployeeMessageTranslator::get('employee.profile_statuses.' . $status->value),
            ],
            ProfileStatus::cases()
        );
    }

    /**
     * @deprecated Kept for backward compatibility. Use paginate() with 'search' filter.
     */
    public function searchCitizens(string $term)
    {
        $filters = $term !== '' ? ['search' => $term] : [];
        return $this->paginate($filters, 50)->items();
    }

    public function getCitizen(int $userId): User
    {
        $user = User::query()->whereKey($userId)->withTrashed()->first();

        if ($user === null || $user->user_type !== UserType::Citizen || $user->trashed()) {
            throw new ApiException('messages.dashboard.citizen_not_found', 404);
        }

        return $user;
    }

    public function getCitizenWithDetails(int $userId): User
    {
        $citizen = $this->getCitizen($userId);

        $citizen->loadCount([
            'licenseApplications as license_applications_count',
            'licenses as licenses_count',
            'fines as fines_count',
            'fines as unpaid_fines_count' => fn ($q) => $q->where('status', 'unpaid'),
        ]);

        if ($citizen->deactivated_by) {
            $citizen->load('deactivatedBy');
        }

        return $citizen;
    }

    public function activate(User $actor, int $citizenId): User
    {
        $citizen = DB::transaction(function () use ($actor, $citizenId): User {
            $citizen = User::query()
                ->where('user_type', UserType::Citizen)
                ->whereKey($citizenId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($citizen->is_active) {
                return $citizen;
            }

            $citizen->update([
                'is_active'           => true,
                'deactivated_at'      => null,
                'deactivated_by'      => null,
                'deactivation_reason' => null,
            ]);

            $this->auditLogs->log(
                $actor,
                'citizen.activated',
                'user',
                $citizen->id,
                ['is_active' => false],
                ['is_active' => true],
            );

            return $citizen->fresh();
        });

        if (! $citizen->is_active) {
            return $citizen;
        }

        try {
            $this->notifications->sendLocalizedToUser(
                $citizen->id,
                'messages.notifications.account_activated_title',
                'messages.notifications.account_activated_body',
                [],
                'account.activated',
            );
        } catch (\Throwable) {
        }

        return $citizen;
    }

    public function deactivate(User $actor, int $citizenId, string $reason): User
    {
        $citizen = DB::transaction(function () use ($actor, $citizenId, $reason): User {
            $citizen = User::query()
                ->where('user_type', UserType::Citizen)
                ->whereKey($citizenId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $citizen->is_active) {
                $citizen->tokens()->delete();
                return $citizen;
            }

            $citizen->update([
                'is_active'           => false,
                'deactivated_at'      => now(),
                'deactivated_by'      => $actor->id,
                'deactivation_reason' => $reason,
            ]);

            $citizen->tokens()->delete();

            $this->auditLogs->log(
                $actor,
                'citizen.deactivated',
                'user',
                $citizen->id,
                ['is_active' => true],
                ['is_active' => false, 'reason' => $reason],
            );

            return $citizen->fresh();
        });

        if ($citizen->is_active) {
            return $citizen;
        }

        try {
            $this->notifications->sendLocalizedToUser(
                $citizen->id,
                'messages.notifications.account_deactivated_title',
                'messages.notifications.account_deactivated_body',
                ['reason' => $reason],
                'account.deactivated',
            );
        } catch (\Throwable) {
        }

        return $citizen;
    }

    public function update(User $actor, User $citizen, array $data): User
    {
        $this->assertCitizen($citizen);

        $allowed = ['name', 'email', 'phone', 'national_id', 'birth_date', 'governorate', 'address'];
        $payload = array_intersect_key($data, array_flip($allowed));

        if (empty($payload)) {
            return $citizen;
        }

        $oldValues = $this->auditPayload($citizen);

        $updatedCitizen = DB::transaction(function () use ($citizen, $payload): User {
            $locked = User::query()->whereKey($citizen->id)->lockForUpdate()->firstOrFail();
            return $this->users->updateUser($locked, $payload);
        });

        $newValues = $this->auditPayload($updatedCitizen);

        $changed = array_filter(
            $newValues,
            fn ($v, $k) => ($oldValues[$k] ?? null) !== $v,
            ARRAY_FILTER_USE_BOTH
        );

        if (! empty($changed)) {
            $this->auditLogs->log(
                $actor,
                'citizen.updated',
                'user',
                $updatedCitizen->id,
                array_intersect_key($oldValues, $changed),
                $changed,
            );
        }

        return $updatedCitizen;
    }

    public function citizenApplications(User $citizen, int $perPage): LengthAwarePaginator
    {
        $this->assertCitizen($citizen);

        return LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->with(['citizen', 'licenseType', 'serviceType'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function citizenLicenses(User $citizen, int $perPage): LengthAwarePaginator
    {
        $this->assertCitizen($citizen);

        return License::query()
            ->where('citizen_id', $citizen->id)
            ->with(['licenseType', 'application'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function citizenFines(User $citizen, int $perPage): LengthAwarePaginator
    {
        $this->assertCitizen($citizen);

        return Fine::query()
            ->where('citizen_id', $citizen->id)
            ->with('license')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function citizenAuditLogs(User $citizen, int $perPage): LengthAwarePaginator
    {
        $this->assertCitizen($citizen);

        return AuditLog::query()
            ->where('entity_type', 'user')
            ->where('entity_id', $citizen->id)
            ->whereIn('action', ['citizen.updated', 'citizen.activated', 'citizen.deactivated'])
            ->with('user')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function assertCitizen(User $user): void
    {
        if ($user->user_type !== UserType::Citizen) {
            throw new ApiException('messages.dashboard.citizen_not_found', 404);
        }
    }

    private function auditPayload(User $user): array
    {
        return [
            'name'        => $user->name,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'national_id' => $user->national_id,
            'birth_date'  => $user->birth_date?->format('Y-m-d'),
            'governorate' => $user->governorate,
            'address'     => $user->address,
        ];
    }
}
