<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DashboardEmployeesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_is_seeded(): void
    {
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
            SuperAdminUserSeeder::class,
        ]);

        $user = User::query()->where('email', 'superadmin@syrtak.gov.sy')->first();
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->user_type?->value);
        $this->assertSame('super_admin', $user->role?->name);
        $this->assertTrue($user->is_active);
    }

    public function test_sample_employees_are_seeded_with_roles(): void
    {
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
            DashboardEmployeesSeeder::class,
        ]);

        $expected = [
            'profile_document_reviewer@syrtak.gov.sy' => 'profile_document_reviewer',
            'fines.employee@syrtak.gov.sy' => 'fines_employee',
            'audit.employee@syrtak.gov.sy' => 'audit_employee',
            'reports.employee@syrtak.gov.sy' => 'reports_employee',
            'settings.employee@syrtak.gov.sy' => 'settings_employee',
            'application.manager@syrtak.gov.sy' => 'application_manager',
            'test.employee@syrtak.gov.sy' => 'test_employee',
            'license.employee@syrtak.gov.sy' => 'license_employee',
            'payment.employee@syrtak.gov.sy' => 'payment_employee',
        ];

        foreach ($expected as $email => $roleName) {
            $user = User::query()->where('email', $email)->first();
            $this->assertNotNull($user, "Missing seeded user: {$email}");
            $this->assertSame('employee', $user->user_type?->value);
            $this->assertSame($roleName, $user->role?->name);
        }
    }

    public function test_dashboard_permissions_are_seeded(): void
    {
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
        ]);

        foreach (['access_dashboard', 'review_profiles', 'manage_employees', 'view_audit_logs'] as $name) {
            $this->assertTrue(
                Permission::query()->where('name', $name)->exists(),
                "Missing permission: {$name}"
            );
        }

        $reviewer = Role::query()->where('name', 'profile_document_reviewer')->firstOrFail();
        $this->assertTrue($reviewer->permissions()->where('name', 'review_profiles')->exists());
    }
}
