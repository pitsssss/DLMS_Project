<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'access_dashboard',
            'manage_employees',
            'view_employees',
            'create_employees',
            'update_employees',
            'disable_employees',
            'reset_employee_passwords',
            'manage_roles',
            'view_roles',
            'assign_roles',
            'manage_permissions',
            'review_profiles',
            'review_documents',
            'view_applications',
            'manage_applications',
            'view_payments',
            'manage_payments',
            'view_appointments',
            'manage_appointments',
            'record_test_result',
            'view_licenses',
            'issue_license',
            'manage_licenses',
            'view_fines',
            'manage_fines',
            'view_audit_logs',
            'view_reports',
            'manage_settings',
            'view_notifications',
            'send_notifications',
            'view_ai_agent_logs',
            'view_ai_agent_reports',
            'manage_users',
            'view_contact_messages',
            'manage_contact_messages',
        ];

        $names = array_values(array_unique($names));

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $allPermissionIds = Permission::query()->pluck('id');

        $rolePermissions = [
            'super_admin' => $names,
            'admin' => $names,
            'profile_document_reviewer' => [
                'access_dashboard',
                'review_profiles',
                'review_documents',
                'view_applications',
            ],
            'fines_employee' => [
                'access_dashboard',
                'view_fines',
                'manage_fines',
                'view_licenses',
            ],
            'audit_employee' => [
                'access_dashboard',
                'view_audit_logs',
            ],
            'reports_employee' => [
                'access_dashboard',
                'view_reports',
            ],
            'settings_employee' => [
                'access_dashboard',
                'manage_settings',
                'view_contact_messages',
                'manage_contact_messages',
            ],
            'application_manager' => [
                'access_dashboard',
                'view_applications',
                'manage_applications',
            ],
            'test_employee' => [
                'access_dashboard',
                'view_appointments',
                'manage_appointments',
                'record_test_result',
            ],
            'license_employee' => [
                'access_dashboard',
                'view_licenses',
                'issue_license',
                'manage_licenses',
                'view_applications',
            ],
            'payment_employee' => [
                'access_dashboard',
                'view_payments',
                'manage_payments',
                'view_applications',
            ],
            'employee' => [
                'access_dashboard',
                'review_profiles',
                'review_documents',
                'record_test_result',
                'issue_license',
                'manage_applications',
                'manage_fines',
                'manage_licenses',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionList) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role === null) {
                continue;
            }

            if ($roleName === 'super_admin' || $roleName === 'admin') {
                $role->permissions()->sync($allPermissionIds);

                continue;
            }

            $ids = Permission::query()->whereIn('name', $permissionList)->pluck('id');
            $role->permissions()->sync($ids);
        }
    }
}
