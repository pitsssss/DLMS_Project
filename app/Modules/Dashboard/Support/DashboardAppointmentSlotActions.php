<?php

namespace App\Modules\Dashboard\Support;

use App\Models\AppointmentSlot;
use App\Models\User;

final class DashboardAppointmentSlotActions
{
    /**
     * @return array{
     *   can_view: bool,
     *   can_update: bool,
     *   can_activate: bool,
     *   can_deactivate: bool,
     *   can_view_bookings: bool,
     *   can_view_audit_logs: bool
     * }
     */
    public static function for(AppointmentSlot $slot, User $actor): array
    {
        $canView = $actor->hasPermission('view_appointments') || $actor->hasPermission('manage_appointments');
        $canManage = $actor->hasPermission('manage_appointments');
        $canAudit = $actor->hasPermission('view_audit_logs');

        return [
            'can_view' => $canView,
            'can_update' => $canManage,
            'can_activate' => $canManage && ! $slot->is_active,
            'can_deactivate' => $canManage && (bool) $slot->is_active,
            'can_view_bookings' => $canView,
            'can_view_audit_logs' => $canAudit,
        ];
    }
}
