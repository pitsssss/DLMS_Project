<?php

namespace App\Modules\Dashboard\Support;

use App\Models\Fee;
use App\Models\User;

final class DashboardFeeActions
{
    /**
     * @return array{
     *   can_view: bool,
     *   can_update: bool,
     *   can_activate: bool,
     *   can_deactivate: bool,
     *   can_view_audit_logs: bool
     * }
     */
    public static function for(Fee $fee, User $actor): array
    {
        $canManage = $actor->hasPermission('manage_settings');
        $canAudit = $actor->hasPermission('view_audit_logs');

        return [
            'can_view' => $canManage,
            'can_update' => $canManage,
            'can_activate' => $canManage && ! $fee->is_active,
            'can_deactivate' => $canManage && (bool) $fee->is_active,
            'can_view_audit_logs' => $canAudit,
        ];
    }
}
