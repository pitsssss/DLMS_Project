<?php

namespace App\Modules\Dashboard\Support\Reports;

use App\Models\User;

final class ReportVisibility
{
    /**
     * @return array<string, bool>
     */
    public static function for(User $user): array
    {
        return [
            'applications' => $user->hasPermission('view_applications') || $user->hasPermission('manage_applications'),
            'citizens' => $user->hasPermission('manage_users'),
            'document_reviews' => $user->hasPermission('review_documents'),
            'tests' => $user->hasPermission('record_test_result')
                || $user->hasPermission('manage_appointments')
                || $user->hasPermission('view_appointments'),
            'appointments' => $user->hasPermission('view_appointments') || $user->hasPermission('manage_appointments'),
            'licenses' => $user->hasPermission('view_licenses')
                || $user->hasPermission('manage_licenses')
                || $user->hasPermission('issue_license'),
            'payments' => $user->hasPermission('view_payments') || $user->hasPermission('manage_payments'),
            'fines' => $user->hasPermission('view_fines') || $user->hasPermission('manage_fines'),
            'employees' => $user->hasPermission('manage_employees') || $user->hasPermission('view_employees'),
            'employee_audit' => $user->hasPermission('view_audit_logs'),
        ];
    }

    public static function can(User $user, string $section): bool
    {
        return (self::for($user)[$section] ?? false) === true;
    }
}
