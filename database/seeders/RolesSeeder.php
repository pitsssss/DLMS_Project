<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'super_admin', 'display_name' => 'مدير النظام العام'],
            ['name' => 'profile_document_reviewer', 'display_name' => 'موظف مراجعة الملفات والوثائق'],
            ['name' => 'fines_employee', 'display_name' => 'موظف الغرامات'],
            ['name' => 'audit_employee', 'display_name' => 'موظف مراقبة السجلات'],
            ['name' => 'reports_employee', 'display_name' => 'موظف التقارير'],
            ['name' => 'settings_employee', 'display_name' => 'موظف الإعدادات'],
            ['name' => 'application_manager', 'display_name' => 'موظف إدارة الطلبات'],
            ['name' => 'test_employee', 'display_name' => 'موظف الاختبارات'],
            ['name' => 'license_employee', 'display_name' => 'موظف إصدار الرخص'],
            ['name' => 'payment_employee', 'display_name' => 'موظف المدفوعات'],
            ['name' => 'citizen', 'display_name' => 'مواطن'],
            ['name' => 'admin', 'display_name' => 'مدير النظام'],
            ['name' => 'employee', 'display_name' => 'موظف'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                ['display_name' => $role['display_name']]
            );
        }
    }
}
