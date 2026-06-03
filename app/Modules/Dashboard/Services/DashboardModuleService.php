<?php

namespace App\Modules\Dashboard\Services;

use App\Models\User;

class DashboardModuleService
{
    /**
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    public function modulesForUser(User $user): array
    {
        $definitions = [
            ['key' => 'overview', 'label' => 'نظرة عامة', 'permission' => 'access_dashboard'],
            ['key' => 'employees', 'label' => 'إدارة الموظفين', 'permission' => 'manage_employees'],
            ['key' => 'roles_permissions', 'label' => 'الأدوار والصلاحيات', 'permission' => 'manage_roles'],
            ['key' => 'profile_reviews', 'label' => 'مراجعة الملفات الشخصية', 'permission' => 'review_profiles'],
            ['key' => 'document_reviews', 'label' => 'مراجعة الوثائق', 'permission' => 'review_documents'],
            ['key' => 'applications', 'label' => 'طلبات الرخص', 'permission' => 'view_applications'],
            ['key' => 'payments', 'label' => 'المدفوعات', 'permission' => 'view_payments'],
            ['key' => 'appointments_tests', 'label' => 'المواعيد والاختبارات', 'permission' => 'view_appointments'],
            ['key' => 'licenses', 'label' => 'الرخص', 'permission' => 'view_licenses'],
            ['key' => 'fines', 'label' => 'الغرامات', 'permission' => 'view_fines'],
            ['key' => 'audit_logs', 'label' => 'سجلات التدقيق', 'permission' => 'view_audit_logs'],
            ['key' => 'reports', 'label' => 'التقارير', 'permission' => 'view_reports'],
            ['key' => 'settings', 'label' => 'الإعدادات', 'permission' => 'manage_settings'],
            ['key' => 'notifications', 'label' => 'الإشعارات', 'permission' => 'view_notifications'],
            ['key' => 'ai_agent_monitoring', 'label' => 'مراقبة المساعد الذكي', 'permission' => 'view_ai_agent_logs'],
        ];

        if ($user->isSuperAdmin()) {
            return array_map(
                static fn (array $def) => [
                    'key' => $def['key'],
                    'label' => $def['label'],
                    'enabled' => true,
                ],
                $definitions
            );
        }

        $modules = [];
        foreach ($definitions as $def) {
            $enabled = $user->hasPermission($def['permission'])
                || ($def['key'] === 'applications' && $user->hasPermission('manage_applications'))
                || ($def['key'] === 'payments' && $user->hasPermission('manage_payments'))
                || ($def['key'] === 'appointments_tests' && $user->hasPermission('manage_appointments'))
                || ($def['key'] === 'licenses' && $user->hasPermission('manage_licenses'))
                || ($def['key'] === 'fines' && $user->hasPermission('manage_fines'))
                || ($def['key'] === 'roles_permissions' && ($user->hasPermission('view_roles') || $user->hasPermission('manage_permissions')))
                || ($def['key'] === 'ai_agent_monitoring' && $user->hasPermission('view_ai_agent_reports'));

            if ($enabled) {
                $modules[] = [
                    'key' => $def['key'],
                    'label' => $def['label'],
                    'enabled' => true,
                ];
            }
        }

        return $modules;
    }
}
