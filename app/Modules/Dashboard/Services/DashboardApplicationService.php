<?php

namespace App\Modules\Dashboard\Services;

use App\Models\LicenseApplication;
use App\Models\AuditLog;
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
            ->with(['citizen', 'licenseType', 'serviceType'])
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
            $statusValue = $filters['status'];
            $query->where('status', $statusValue);
        }

        if (! empty($filters['license_type_name'])) {
            $licenseTypeName = trim($filters['license_type_name']);

            $query->whereHas('licenseType', function ($licenseTypeQuery) use ($licenseTypeName) {
                $licenseTypeQuery->where('name', $licenseTypeName);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Get application extra details and audit logs formatted for dashboard details view.
     *
     * @param string $applicationNumber
     * @return array<string, mixed>
     */
    public function getDetailsByNumber(string $applicationNumber): array
    {
        $application = LicenseApplication::query()
            ->with('currentTestType')
            ->where('application_number', $applicationNumber)
            ->firstOrFail();

        // Load audit logs related to this license application
        $logs = AuditLog::query()
            ->with('user')
            ->where('entity_type', 'license_application')
            ->where('entity_id', $application->id)
            ->orderByDesc('id')
            ->get();

        $formattedLogs = $this->formatAuditLogs($logs);

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'extra_details' => [
                'current_test_type' => $application->currentTestType ? [
                    'name' => $application->currentTestType->name,
                ] : null,
                'rejection_reason' => $application->rejection_reason,
                'approved_at' => $application->approved_at?->format('Y-m-d H:i:s') ?? null,
                'issued_at' => $application->issued_at?->format('Y-m-d H:i:s') ?? null,
                'created_at' => $application->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $application->updated_at?->format('Y-m-d H:i:s'),
            ],
            'audit_logs' => $formattedLogs,
        ];
    }

    /**
     * Format audit logs into readable change arrays.
     *
     * @param \Illuminate\Database\Eloquent\Collection $logs
     * @return array<int, array<string, mixed>>
     */
    private function formatAuditLogs($logs): array
    {
        $statusLabels = [
            'draft' => 'مسودة',
            'documents_under_review' => 'مراجعة الوثائق',
            'documents_rejected' => 'رفض الوثائق',
            'payment_pending' => 'بانتظار الدفع',
            'payment_completed' => 'تم الدفع',
            'appointment_pending' => 'بانتظار الموعد',
            'in_testing' => 'قيد الاختبار',
            'waiting_retest' => 'بانتظار إعادة الاختبار',
            'approved' => 'مقبول',
            'license_issued' => 'تم إصدار الرخصة',
            'rejected' => 'مرفوض',
            'cancelled' => 'ملغى',
            'administrative_review' => 'مراجعة إدارية',
        ];

        $fieldLabels = [
            'application_number' => 'رقم الطلب',
            'status' => 'الحالة',
            'citizen_id' => 'المواطن',
            'license_type_id' => 'فئة الرخصة',
            'service_type_id' => 'نوع الخدمة',
            'current_test_type_id' => 'نوع الاختبار الحالي',
            'rejection_reason' => 'سبب الرفض',
            'submitted_at' => 'تاريخ التقديم',
            'approved_at' => 'تاريخ الموافقة',
            'issued_at' => 'تاريخ إصدار الرخصة',
            'created_at' => 'تاريخ الإنشاء',
            'updated_at' => 'آخر تحديث',
        ];

        $actionLabels = [
            'created' => 'إنشاء',
            'updated' => 'تحديث',
            'deleted' => 'حذف',
            'restored' => 'استعادة',
            'approved' => 'موافقة',
            'rejected' => 'رفض',
            'status_changed' => 'تغيير الحالة',
        ];

        $result = [];

        foreach ($logs as $log) {
            $changes = [];

            $old = (array) ($log->old_values ?? []);
            $new = (array) ($log->new_values ?? []);

            $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

            foreach ($keys as $key) {
                $oldValue = array_key_exists($key, $old) ? $old[$key] : null;
                $newValue = array_key_exists($key, $new) ? $new[$key] : null;

                // Only include if actually changed
                if ($oldValue === $newValue) {
                    continue;
                }

                $oldLabel = $oldValue === null || $oldValue === '' ? 'غير محدد' : (string) $oldValue;
                $newLabel = $newValue === null || $newValue === '' ? 'غير محدد' : (string) $newValue;

                // Translate status values
                if ($key === 'status') {
                    $oldLabel = $oldValue === null || $oldValue === '' ? 'غير محدد' : ($statusLabels[$oldValue] ?? (string) $oldValue);
                    $newLabel = $newValue === null || $newValue === '' ? 'غير محدد' : ($statusLabels[$newValue] ?? (string) $newValue);
                }

                $changes[] = [
                    'field' => $key,
                    'field_label' => $fieldLabels[$key] ?? $key,
                    'old_value' => $oldValue,
                    'old_label' => $oldLabel,
                    'new_value' => $newValue,
                    'new_label' => $newLabel,
                ];
            }

            $action = $log->action;
            // Support action names like 'application.status_changed' by mapping the last segment
            $actionKeyParts = explode('.', $action);
            $actionKey = end($actionKeyParts);
            $actionLabel = $actionLabels[$actionKey] ?? $action;

            $result[] = [
                'id' => $log->id,
                'action' => $action,
                'action_label' => $actionLabel,
                'performed_by' => $log->user ? [
                    'id' => $log->user->id,
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
