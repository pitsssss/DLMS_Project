<?php

namespace App\Modules\Dashboard\Services;

use App\Models\LicenseApplication;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DashboardApplicationService
{
    /**
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = LicenseApplication::query()
            ->with(['citizen', 'licenseType', 'serviceType', 'currentTestType'])
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('application_number', 'like', '%' . $filters['search'] . '%')
                    ->orWhereHas('citizen', function ($userQuery) use ($filters) {
                        $userQuery->where('name', 'like', '%' . $filters['search'] . '%');
                    });
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['license_type_id'])) {
            $query->where('license_type_id', $filters['license_type_id']);
        }

        return $query->paginate($perPage);
    }
}
