<?php

namespace App\Modules\Dashboard\Support;

use App\Enums\LicenseStatus;
use App\Models\License;
use App\Models\User;
use App\Modules\Licenses\Support\LicenseEffectiveStatus;

final class DashboardLicenseActions
{
    /**
     * @return array<string, bool>
     */
    public static function for(User $actor, License $license): array
    {
        $canManage = $actor->hasPermission('manage_licenses');
        $canViewApps = $actor->hasPermission('view_applications') || $actor->hasPermission('manage_applications');
        $canAudit = $actor->hasPermission('view_audit_logs');
        $effective = LicenseEffectiveStatus::resolve($license);

        $canBlock = $canManage && $effective === LicenseStatus::Active;
        $canUnblock = $canManage && $license->status === LicenseStatus::Blocked;

        return [
            'can_view' => true,
            'can_print' => true,
            'can_block' => $canBlock,
            'can_unblock' => $canUnblock,
            'can_view_application' => $canViewApps && $license->application_id !== null,
            'can_view_history' => true,
            'can_view_audit_logs' => $canAudit,
        ];
    }
}
