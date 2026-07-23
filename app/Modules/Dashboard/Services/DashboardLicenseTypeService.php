<?php

namespace App\Modules\Dashboard\Services;

use App\Exceptions\ApiException;
use App\Models\LicenseType;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardLicenseTypeService
{
    private const ALLOWED_SORT_COLUMNS = ['name', 'code', 'created_at'];

    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @param  array{
     *     search?: string|null,
     *     is_active?: bool|null,
     *     sort_by?: string,
     *     sort_direction?: string,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, LicenseType>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = strtolower((string) ($filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortBy, self::ALLOWED_SORT_COLUMNS, true)) {
            $sortBy = 'created_at';
        }

        return $this->filteredQuery($filters)
            ->orderBy($sortBy, $sortDirection)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    public function statistics(): array
    {
        $total = LicenseType::query()->count();
        $active = LicenseType::query()->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
        ];
    }

    public function get(int $id): LicenseType
    {
        $type = LicenseType::query()->find($id);

        if ($type === null) {
            throw new ApiException('messages.dashboard.license_type_not_found', 404);
        }

        return $type;
    }

    /**
     * @param  array{name: string, code: string, minimum_age: int, validity_years: int, is_active?: bool|null}  $data
     */
    public function create(array $data): LicenseType
    {
        return DB::transaction(function () use ($data) {
            $type = LicenseType::query()->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'minimum_age' => $data['minimum_age'],
                'validity_years' => $data['validity_years'],
                'is_active' => array_key_exists('is_active', $data) && $data['is_active'] !== null
                    ? (bool) $data['is_active']
                    : true,
            ]);

            $this->auditLogs->log(
                request()->user(),
                'license_type.created',
                'license_type',
                $type->id,
                null,
                [
                    'name' => $type->name,
                    'code' => $type->code,
                    'minimum_age' => $type->minimum_age,
                    'validity_years' => $type->validity_years,
                    'is_active' => (bool) $type->is_active,
                ],
                request()
            );

            return $type;
        });
    }

    /**
     * @param  array{name?: string, minimum_age?: int, validity_years?: int, is_active?: bool|null}  $data
     */
    public function update(LicenseType $type, array $data): LicenseType
    {
        return DB::transaction(function () use ($type, $data) {
            $old = [
                'name' => $type->name,
                'code' => $type->code,
                'minimum_age' => $type->minimum_age,
                'validity_years' => $type->validity_years,
                'is_active' => (bool) $type->is_active,
            ];

            $payload = [];

            if (array_key_exists('name', $data)) {
                $payload['name'] = $data['name'];
            }
            if (array_key_exists('minimum_age', $data)) {
                $payload['minimum_age'] = $data['minimum_age'];
            }
            if (array_key_exists('validity_years', $data)) {
                $payload['validity_years'] = $data['validity_years'];
            }
            if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
                $payload['is_active'] = (bool) $data['is_active'];
            }

            if (! empty($payload)) {
                $type->fill($payload)->save();
            }

            $type->refresh();

            $this->auditLogs->log(
                request()->user(),
                'license_type.updated',
                'license_type',
                $type->id,
                $old,
                [
                    'name' => $type->name,
                    'code' => $type->code,
                    'minimum_age' => $type->minimum_age,
                    'validity_years' => $type->validity_years,
                    'is_active' => (bool) $type->is_active,
                ],
                request()
            );

            return $type;
        });
    }

    public function setActive(LicenseType $type, bool $desired): LicenseType
    {
        if ((bool) $type->is_active === $desired) {
            return $type;
        }

        return DB::transaction(function () use ($type, $desired) {
            $oldActive = (bool) $type->is_active;
            $type->update(['is_active' => $desired]);

            $this->auditLogs->log(
                request()->user(),
                $desired ? 'license_type.activated' : 'license_type.deactivated',
                'license_type',
                $type->id,
                ['is_active' => $oldActive],
                ['is_active' => $desired],
                request()
            );

            return $type->refresh();
        });
    }

    /**
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     * @return Builder<LicenseType>
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = LicenseType::query();

        if (! empty($filters['search'])) {
            $like = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query;
    }
}
