<?php

namespace App\Modules\Dashboard\Support;

use App\Models\User;

final class DashboardLicenseIssuanceActions
{
    /**
     * @param  array{is_ready?: bool}  $readiness
     * @return array{can_issue_license: bool, can_view_application: bool}
     */
    public static function for(User $actor, array $readiness): array
    {
        return [
            'can_issue_license' => $actor->hasPermission('issue_license')
                && (bool) ($readiness['is_ready'] ?? false),
            'can_view_application' => $actor->hasPermission('view_applications')
                || $actor->hasPermission('manage_applications'),
        ];
    }
}
