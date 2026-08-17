<?php

namespace App\Modules\Dashboard\Services;

use App\Models\AuditLog;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DashboardApplicationService
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = LicenseApplication::query()
            ->with(['citizen', 'licenseType', 'serviceType'])
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($q) use ($search) {
                $q->where('application_number', 'like', '%' . $search . '%')
                    ->orWhereHas('citizen', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', trim($filters['status']));
        }

        if (! empty($filters['license_type_code'])) {
            $code = trim($filters['license_type_code']);
            $query->whereHas('licenseType', function ($q) use ($code) {
                $q->where('code', $code);
            });
        }

        if (! empty($filters['service_type_code'])) {
            $code = trim($filters['service_type_code']);
            $query->whereHas('serviceType', function ($q) use ($code) {
                $q->where('code', $code);
            });
        }

        if (! empty($filters['test_type_code'])) {
            $code = trim($filters['test_type_code']);
            $query->whereHas('currentTestType', function ($q) use ($code) {
                $q->where('code', $code);
            });
        }

        return $query->paginate($perPage);
    }

    public function getDetailsByNumber(string $applicationNumber): LicenseApplication
    {
        $application = LicenseApplication::query()
            ->with([
                'citizen',
                'licenseType',
                'serviceType',
                'currentTestType',
                'relatedLicense',
            ])
            ->where('application_number', $applicationNumber)
            ->firstOrFail();

        $logs = AuditLog::query()
            ->with('user')
            ->where('entity_type', 'license_application')
            ->where('entity_id', $application->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        /*
         * لا نحتاج علاقة حقيقية بالموديل.
         * فقط نخزن الـ logs كـ loaded relation حتى يقرأها الـ Resource.
         */
        $application->setRelation('dashboardAuditLogs', $logs);

        return $application;
    }


    private function formatAuditLogs($logs): array
    {
        $result = [];

        foreach ($logs as $log) {
            $changes = [];

            $old = (array) ($log->old_values ?? []);
            $new = (array) ($log->new_values ?? []);

            $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

            foreach ($keys as $key) {
                $oldValue = array_key_exists($key, $old) ? $old[$key] : null;
                $newValue = array_key_exists($key, $new) ? $new[$key] : null;

                if ($oldValue === $newValue) {
                    continue;
                }

                $changes[] = [
                    'field' => $key,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ];
            }

            $result[] = [
                'id' => $log->id,
                'action' => $log->action,
                'performed_by' => $log->user ? [
                    'name' => $log->user->name,
                ] : null,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'changes' => $changes,
            ];
        }

        return $result;
    }


}
