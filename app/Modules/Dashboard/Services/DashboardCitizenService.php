<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\Repositories\AuthRepository;
use App\Services\AuditLogService;
use App\Support\EmployeeMessageTranslator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DashboardCitizenService
{
    public function __construct(
        private readonly AuthRepository $users,
        private readonly AuditLogService $auditLogs,
    ) {}


    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = User::query()
            ->where('user_type', UserType::Citizen)
            ->with('role')
            ->orderByDesc('id');

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['profile_status'])) {
            $query->where('profile_status', trim($filters['profile_status']));
        }

        return $query->paginate($perPage);
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

    public function searchCitizens(string $term)
    {
        $term = trim($term);

        $query = User::query()
            ->where('user_type', UserType::Citizen)
            ->with('role');

        if (filter_var($term, FILTER_VALIDATE_EMAIL)) {
            $query->where('email', $term);
        } elseif (preg_match('/^\d{10}$/', $term)) {
            $query->where('phone', $term);
        } elseif (preg_match('/^\d{11}$/', $term)) {
            $query->where('national_id', $term);
        } else {
            $query->where('name', 'LIKE', '%' . $term . '%');
        }

        return $query->limit(50)->get();
    }

    public function getCitizen(int $userId): User
    {
        $user = User::query()->whereKey($userId)->with('role')->first();

        if ($user === null || $user->user_type !== UserType::Citizen) {
            throw new ApiException('messages.dashboard.citizen_not_found', 404);
        }

        return $user;
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

    public function update(?User $actor, User $user, array $data): User
    {
        $this->assertCitizen($user);

        $oldValues = $this->auditPayload($user);

        $payload = [];

        foreach (['name', 'email', 'phone', 'national_id', 'birth_date', 'governorate', 'address'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = (bool) $data['is_active'];
        }

        $citizen = $this->users->updateUser($user, $payload);

        $this->auditLogs->log(
            $actor,
            'citizen.updated',
            'user',
            $citizen->id,
            $oldValues,
            $this->auditPayload($citizen)
        );

        return $citizen;
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
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'national_id' => $user->national_id,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
