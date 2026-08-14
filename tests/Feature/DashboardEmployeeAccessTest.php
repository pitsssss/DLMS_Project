<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardEmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function superAdmin(): User
    {
        return User::factory()->dashboardAdmin('super_admin')->create(['password' => 'password']);
    }

    public function test_employee_access_payload_and_role_sync(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $employee = User::factory()->dashboardEmployee('fines_employee')->create();
        $reports = Role::query()->where('name', 'reports_employee')->firstOrFail();

        $access = $this->getJson("/api/dashboard/employees/{$employee->id}/access")->assertOk();
        $this->assertSame('fines_employee', $access->json('data.role.name'));
        $this->assertContains('view_fines', $access->json('data.effective_permissions'));

        $this->patchJson("/api/dashboard/employees/{$employee->id}/roles", [
            'role_id' => $reports->id,
            'reason' => 'نقل إلى التقارير',
        ])->assertOk()->assertJsonPath('data.role.name', 'reports_employee');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.roles_updated',
            'entity_id' => $employee->id,
        ]);
    }

    public function test_direct_permissions_union_and_removal_keeps_inherited(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $employee = User::factory()->dashboardEmployee('fines_employee')->create();

        $viewLicenses = Permission::query()->where('name', 'view_licenses')->firstOrFail();
        $viewFines = Permission::query()->where('name', 'view_fines')->firstOrFail();

        // Grant an extra direct permission already inherited + a new one.
        $this->patchJson("/api/dashboard/employees/{$employee->id}/direct-permissions", [
            'permission_ids' => [$viewLicenses->id, $viewFines->id],
            'reason' => 'منح عرض الرخص مباشرة',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.direct_permissions_updated',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
        ]);

        $employee->refresh();
        $this->assertTrue($employee->hasPermission('view_licenses'));
        $this->assertTrue($employee->hasPermission('view_fines'));

        // Remove all direct permissions; role-inherited view_fines/view_licenses remain if on role.
        $this->patchJson("/api/dashboard/employees/{$employee->id}/direct-permissions", [
            'permission_ids' => [],
            'reason' => 'إزالة الصلاحيات المباشرة',
        ])->assertOk();

        $employee->refresh()->load(['role.permissions', 'directPermissions']);
        $this->assertSame([], $employee->directPermissionNames());
        $this->assertTrue($employee->hasPermission('view_fines'));
        $this->assertTrue($employee->hasPermission('view_licenses'));
    }

    public function test_citizen_cannot_receive_employee_role_via_access_api(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $citizen = User::factory()->withApprovedProfile()->create();
        $role = Role::query()->where('name', 'fines_employee')->firstOrFail();

        $this->patchJson("/api/dashboard/employees/{$citizen->id}/roles", [
            'role_id' => $role->id,
            'reason' => 'محاولة غير صالحة',
        ])->assertStatus(404);
    }

    public function test_archived_role_cannot_be_assigned(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $created = $this->postJson('/api/dashboard/access-control/roles', [
            'name' => 'archived_assign_test',
            'display_name' => 'مؤرشف',
        ])->assertOk();
        $roleId = (int) $created->json('data.id');

        $this->patchJson("/api/dashboard/access-control/roles/{$roleId}/archive", [
            'reason' => 'أرشفة للاختبار',
        ])->assertOk();

        $employee = User::factory()->dashboardEmployee('fines_employee')->create();
        $this->patchJson("/api/dashboard/employees/{$employee->id}/roles", [
            'role_id' => $roleId,
            'reason' => 'محاولة تعيين مؤرشف',
        ])->assertStatus(422);
    }
}
