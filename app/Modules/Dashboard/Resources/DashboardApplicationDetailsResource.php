<?php

namespace App\Modules\Dashboard\Resources;

use App\Enums\DocumentRejectionReason;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use App\Support\ArabicMessageTranslator;
use App\Support\EmployeeMessageTranslator;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class DashboardApplicationDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LicenseApplication $application */
        $application = $this->resource;

        /** @var Collection $logs */
        $logs = $application->relationLoaded('dashboardAuditLogs')
            ? $application->getRelation('dashboardAuditLogs')
            : collect();

        $lookups = $this->buildAuditLookups($logs);

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,

            'header' => [
                'application_number' => $application->application_number,
                'citizen_name' => $application->citizen?->name,
                'status' => $this->statusLabel($application->status),
                'service_type' => $this->serviceTypeLabel($application->serviceType?->code),
                'license_type' => $this->licenseTypeLabel($application->licenseType?->code),
                'created_at' => $this->formatDate($application->created_at),
                'updated_at' => $this->formatDate($application->updated_at),
            ],

            'citizen_info' => $this->formatCitizenInfo($application->citizen),

            'workflow_steps' => $this->buildWorkflowSteps($application, $logs),

            'extra_details' => [
                'current_test_type' => $application->currentTestType
                    ? $this->testTypeLabel($application->currentTestType->code)
                    : null,

                'rejection_reason' => $application->rejection_reason,
                'approved_at' => $this->formatDate($application->approved_at),
                'issued_at' => $this->formatDate($application->issued_at),
                'created_at' => $this->formatDate($application->created_at),
                'updated_at' => $this->formatDate($application->updated_at),
            ],

            'audit_logs' => $this->formatAuditLogs($logs, $lookups),
        ];
    }

    private function formatCitizenInfo(?User $citizen): ?array
    {
        if (! $citizen) {
            return null;
        }

        return [
            'name' => $citizen->name,
            'phone' => $citizen->phone,
            'national_id' => $citizen->national_id,
            'email' => $citizen->email,

            'birth_date' => $this->formatDateOnly($citizen->birth_date),
            'governorate' => $citizen->governorate,
            'address' => $citizen->address,

            'profile_completed' => $this->yesNo($citizen->profile_completed),
            'profile_status' => $this->profileStatusLabel($citizen->profile_status),
            'profile_rejection_reason' => $citizen->profile_rejection_reason,

            'profile_reviewed_at' => $this->formatDate($citizen->profile_reviewed_at),
            'profile_submitted_at' => $this->formatDate($citizen->profile_submitted_at),

            'is_active' => $this->activeLabel($citizen->is_active),

            'phone_verified_at' => $this->formatDate($citizen->phone_verified_at),
            'email_verified_at' => $this->formatDate($citizen->email_verified_at),
        ];
    }

    private function buildWorkflowSteps(LicenseApplication $application, Collection $logs): array
    {
        $status = $this->stringValue($application->status);

        $currentIndex = match ($status) {
            'draft' => 0,

            'documents_under_review',
            'documents_rejected',
            'administrative_review' => 1,

            'payment_pending',
            'payment_completed' => 2,

            'appointment_pending',
            'in_testing',
            'waiting_retest',
            'approved',
            'rejected' => 3,

            'license_issued' => 4,

            default => 0,
        };

        $steps = [
            [
                'key' => 'created',
                'label' => 'إنشاء الطلب',
                'date' => $this->formatDate($application->created_at),
            ],
            [
                'key' => 'documents_review',
                'label' => 'مراجعة الوثائق',
                'date' => $this->firstStatusDate($logs, [
                    'documents_under_review',
                    'documents_rejected',
                ]),
            ],
            [
                'key' => 'payment',
                'label' => 'الدفع',
                'date' => $this->firstStatusDate($logs, [
                    'payment_pending',
                    'payment_completed',
                ]),
            ],
            [
                'key' => 'test',
                'label' => 'الاختبار',
                'date' => $this->firstStatusDate($logs, [
                    'appointment_pending',
                    'in_testing',
                    'waiting_retest',
                    'approved',
                    'rejected',
                ]),
            ],
            [
                'key' => 'issuance',
                'label' => 'الإصدار',
                'date' => $this->formatDate($application->issued_at)
                    ?? $this->firstStatusDate($logs, ['license_issued']),
            ],
        ];

        foreach ($steps as $index => &$step) {
            if ($status === 'license_issued') {
                $step['state'] = 'completed';
                continue;
            }

            if ($status === 'documents_rejected' && $step['key'] === 'documents_review') {
                $step['state'] = 'failed';
                continue;
            }

            if ($status === 'rejected' && $step['key'] === 'test') {
                $step['state'] = 'failed';
                continue;
            }

            if ($status === 'cancelled' && $index === $currentIndex) {
                $step['state'] = 'cancelled';
                continue;
            }

            if ($index < $currentIndex) {
                $step['state'] = 'completed';
            } elseif ($index === $currentIndex) {
                $step['state'] = 'current';
            } else {
                $step['state'] = 'pending';
            }
        }

        unset($step);

        return $steps;
    }

    private function firstStatusDate(Collection $logs, array $statuses): ?string
    {
        $log = $logs
            ->sortBy('created_at')
            ->first(function ($log) use ($statuses) {
                $new = (array) ($log->new_values ?? []);
                $newStatus = $this->stringValue($new['status'] ?? null);

                return $newStatus !== null
                    && in_array($newStatus, $statuses, true);
            });

        return $this->formatDate($log?->created_at);
    }

    private function formatAuditLogs(Collection $logs, array $lookups): array
    {
        return $logs->map(function ($log) use ($lookups) {
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
                    'field_label' => $this->auditFieldLabel($key),
                    'old' => $this->enumValue($oldValue),
                    'new' => $this->enumValue($newValue),
                    'old_label' => $this->auditValueLabel($key, $oldValue, $lookups),
                    'new_label' => $this->auditValueLabel($key, $newValue, $lookups),
                ];
            }

            return [
                'id' => $log->id,
                'action' => $this->auditActionLabel($log->action),
                'performed_by' => $log->user?->name,
                'created_at' => $this->formatDate($log->created_at),
                'changes' => $changes,
                'technical_details' => [
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                ],
            ];
        })->values()->all();
    }

    private function buildAuditLookups(Collection $logs): array
    {
        $licenseTypeIds = [];
        $serviceTypeIds = [];
        $testTypeIds = [];
        $userIds = [];

        foreach ($logs as $log) {
            $old = (array) ($log->old_values ?? []);
            $new = (array) ($log->new_values ?? []);

            foreach ([$old, $new] as $values) {
                $licenseTypeId = $values['license_type_id'] ?? null;
                $serviceTypeId = $values['service_type_id'] ?? null;
                $testTypeId = $values['test_type_id'] ?? null;
                $currentTestTypeId = $values['current_test_type_id'] ?? null;

                if (is_numeric($licenseTypeId)) {
                    $licenseTypeIds[] = (int) $licenseTypeId;
                }

                if (is_numeric($serviceTypeId)) {
                    $serviceTypeIds[] = (int) $serviceTypeId;
                }

                if (is_numeric($testTypeId)) {
                    $testTypeIds[] = (int) $testTypeId;
                }

                if (is_numeric($currentTestTypeId)) {
                    $testTypeIds[] = (int) $currentTestTypeId;
                }

                foreach (['user_id', 'citizen_id', 'profile_reviewed_by'] as $userField) {
                    $userId = $values[$userField] ?? null;

                    if (is_numeric($userId)) {
                        $userIds[] = (int) $userId;
                    }
                }
            }
        }

        return [
            'license_type_id' => LicenseType::query()
                ->whereIn('id', array_unique($licenseTypeIds))
                ->get()
                ->mapWithKeys(fn ($item) => [
                    (string) $item->id => $this->licenseTypeLabel($item->code),
                ])
                ->all(),

            'service_type_id' => ServiceType::query()
                ->whereIn('id', array_unique($serviceTypeIds))
                ->get()
                ->mapWithKeys(fn ($item) => [
                    (string) $item->id => $this->serviceTypeLabel($item->code),
                ])
                ->all(),

            'test_type_id' => TestType::query()
                ->whereIn('id', array_unique($testTypeIds))
                ->get()
                ->mapWithKeys(fn ($item) => [
                    (string) $item->id => $this->testTypeLabel($item->code),
                ])
                ->all(),

            'user_id' => User::query()
                ->whereIn('id', array_unique($userIds))
                ->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id) => [
                    (string) $id => $name,
                ])
                ->all(),
        ];
    }

    private function auditValueLabel(string $field, mixed $value, array $lookups): mixed
    {
        $value = $this->enumValue($value);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $this->yesNo($value);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($value instanceof CarbonInterface) {
            return $this->formatDate($value);
        }

        $valueAsString = (string) $value;

        return match ($field) {
            'status' => $this->statusLabel($valueAsString),

            'service_type_code' => $this->serviceTypeLabel($valueAsString),
            'license_type_code' => $this->licenseTypeLabel($valueAsString),

            'test_type_code',
            'current_test_type_code' => $this->testTypeLabel($valueAsString),

            'license_type_id' => $lookups['license_type_id'][$valueAsString] ?? $valueAsString,
            'service_type_id' => $lookups['service_type_id'][$valueAsString] ?? $valueAsString,

            'test_type_id',
            'current_test_type_id' => $lookups['test_type_id'][$valueAsString] ?? $valueAsString,

            'user_id',
            'citizen_id',
            'profile_reviewed_by' => $lookups['user_id'][$valueAsString] ?? $valueAsString,

            'profile_completed' => $this->yesNo($valueAsString),
            'is_active' => $this->activeLabel($valueAsString),

            'profile_status' => $this->profileStatusLabel($valueAsString),

            'rejection_reason_label' => ArabicMessageTranslator::resolveStoredLabel($valueAsString),

            'rejection_reason_code' => DocumentRejectionReason::tryFrom($valueAsString)?->label()
                ?? ArabicMessageTranslator::resolveStoredLabel($valueAsString),

            default => ArabicMessageTranslator::resolveStoredLabel($valueAsString) ?? $valueAsString,
        };
    }

    private function auditFieldLabel(string $field): string
    {
        return EmployeeMessageTranslator::get('employee.audit.fields.' . $field);
    }

    private function auditActionLabel(?string $action): string
    {
        if (! $action) {
            return 'إجراء غير محدد';
        }

        return EmployeeMessageTranslator::get('employee.audit.actions.' . $action);
    }

    private function statusLabel(mixed $status): ?string
    {
        $status = $this->stringValue($status);

        if (! $status) {
            return null;
        }

        return EmployeeMessageTranslator::get('employee.statuses.' . $status);
    }

    private function serviceTypeLabel(mixed $code): ?string
    {
        $code = $this->stringValue($code);

        if (! $code) {
            return null;
        }

        return EmployeeMessageTranslator::get('employee.services.' . $code);
    }

    private function licenseTypeLabel(mixed $code): ?string
    {
        $code = $this->stringValue($code);

        if (! $code) {
            return null;
        }

        return EmployeeMessageTranslator::get('employee.license_types.' . $code);
    }

    private function testTypeLabel(mixed $code): ?string
    {
        $code = $this->stringValue($code);

        if (! $code) {
            return null;
        }

        return EmployeeMessageTranslator::get('employee.test_types.' . $code);
    }

    private function profileStatusLabel(mixed $status): ?string
    {
        $status = $this->stringValue($status);

        if (! $status) {
            return null;
        }

        return match ($status) {
            'incomplete' => 'غير مكتمل',
            'pending_review' => 'بانتظار المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            default => $status,
        };
    }

    private function yesNo(mixed $value): ?string
    {
        $value = $this->enumValue($value);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1 ? 'نعم' : 'لا';
        }

        $valueAsString = strtolower((string) $value);

        return match ($valueAsString) {
            '1', 'true', 'yes' => 'نعم',
            '0', 'false', 'no' => 'لا',
            default => (string) $value,
        };
    }

    private function activeLabel(mixed $value): ?string
    {
        $value = $this->enumValue($value);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'نشط' : 'غير نشط';
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1 ? 'نشط' : 'غير نشط';
        }

        $valueAsString = strtolower((string) $value);

        return match ($valueAsString) {
            '1', 'true', 'yes' => 'نشط',
            '0', 'false', 'no' => 'غير نشط',
            default => (string) $value,
        };
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function formatDateOnly(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function stringValue(mixed $value): ?string
    {
        $value = $this->enumValue($value);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
