<?php

namespace App\Modules\Dashboard\Resources;

use App\Support\EmployeeMessageTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class DashboardCitizenDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $canViewAuditLogs = $actor && $actor->hasPermission('view_audit_logs');

        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'national_id' => $this->national_id,
            'birth_date'  => $this->birth_date?->format('Y-m-d'),
            'governorate' => $this->governorate,
            'address'     => $this->address,

            'is_active'      => (bool) $this->is_active,
            'account_status' => [
                'value' => $this->is_active ? 'active' : 'inactive',
                'label' => $this->is_active ? 'فعال' : 'غير فعال',
            ],

            'profile_status' => $this->profile_status?->value,
            'profile_status_info' => $this->profile_status ? [
                'value' => $this->profile_status->value,
                'label' => EmployeeMessageTranslator::get('employee.profile_statuses.' . $this->profile_status->value),
            ] : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'deactivation' => $this->is_active ? null : [
                'reason'         => $this->deactivation_reason,
                'deactivated_at' => $this->deactivated_at?->toIso8601String(),
                'deactivated_by' => $this->deactivated_by && $this->relationLoaded('deactivatedBy') && $this->deactivatedBy
                    ? ['id' => $this->deactivatedBy->id, 'name' => $this->deactivatedBy->name]
                    : null,
            ],

            'counts' => [
                'applications' => (int) ($this->license_applications_count ?? 0),
                'licenses'     => (int) ($this->licenses_count ?? 0),
                'fines'        => (int) ($this->fines_count ?? 0),
                'unpaid_fines' => (int) ($this->unpaid_fines_count ?? 0),
            ],

            'actions' => [
                'can_activate'          => ! $this->is_active,
                'can_deactivate'        => (bool) $this->is_active,
                'can_edit'              => true,
                'can_view_applications' => true,
                'can_view_licenses'     => true,
                'can_view_fines'        => true,
                'can_view_audit_logs'   => $canViewAuditLogs,
            ],
        ];
    }
}
