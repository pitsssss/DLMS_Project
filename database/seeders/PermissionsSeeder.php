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
            'review_documents',
            'record_test_result',
            'issue_license',
            'manage_applications',
            'manage_settings',
            'manage_users',
            'view_reports',
            'view_audit_logs',
            'manage_fines',
            'manage_licenses',
        ];

        $permissionIds = [];
        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name]);
            $permissionIds[] = $permission->id;
        }

        $admin = Role::where('name', 'admin')->firstOrFail();
        $admin->permissions()->sync($permissionIds);

        $employee = Role::where('name', 'employee')->firstOrFail();
        $employeePermissionNames = [
            'review_documents',
            'record_test_result',
            'issue_license',
            'manage_applications',
            'manage_fines',
            'manage_licenses',
        ];
        $employee->permissions()->sync(
            Permission::whereIn('name', $employeePermissionNames)->pluck('id')
        );
    }
}
