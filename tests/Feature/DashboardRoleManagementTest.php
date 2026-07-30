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

class DashboardRoleManagementTest extends TestCase
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

    public function test_list_roles_supports_filters_and_pagination(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/dashboard/access-control/roles?per_page=5&is_system=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['items', 'pagination']]);
    }

    public function test_create_custom_role_and_reject_reserved_name(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $permIds = Permission::query()
            ->whereIn('name', ['access_dashboard', 'view_fines'])
            ->pluck('id')
            ->all();

        $created = $this->postJson('/api/dashboard/access-control/roles', [
            'name' => 'custom_ops_role',
            'display_name' => 'دور تشغيلي مخصص',
            'description' => 'دور اختبار',
            'permission_ids' => $permIds,
        ])->assertOk();

        $this->assertSame('custom_ops_role', $created->json('data.name'));
        $this->assertSame(1, $created->json('data.version'));

        $this->postJson('/api/dashboard/access-control/roles', [
            'name' => 'super_admin',
            'display_name' => 'محاولة',
        ])->assertStatus(422);
    }

    public function test_update_role_version_conflict_returns_409(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $role = Role::query()->where('name', 'fines_employee')->firstOrFail();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}", [
            'display_name' => 'موظف الغرامات المحدّث',
            'version' => 999,
        ])->assertStatus(409);
    }

    public function test_protected_super_admin_role_permissions_cannot_be_synced(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $permId = Permission::query()->where('name', 'access_dashboard')->value('id');

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/permissions", [
            'permission_ids' => [$permId],
            'version' => $role->version,
            'reason' => 'محاولة غير مسموحة',
            'password_confirmation' => 'password',
        ])->assertStatus(422);
    }

    public function test_sync_role_permissions_updates_and_requires_reason(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $role = Role::query()->where('name', 'fines_employee')->firstOrFail();
        $ids = Permission::query()
            ->whereIn('name', ['access_dashboard', 'view_fines'])
            ->pluck('id')
            ->all();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/permissions", [
            'permission_ids' => $ids,
            'version' => $role->version,
        ])->assertStatus(422);

        $response = $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/permissions", [
            'permission_ids' => $ids,
            'version' => $role->version,
            'reason' => 'تقليص صلاحيات الغرامات',
        ])->assertOk();

        $this->assertContains('access_dashboard', $response->json('data.role.permission_names'));
        $this->assertNotContains('manage_fines', $response->json('data.role.permission_names'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access_role.permissions_updated',
            'entity_type' => 'role',
            'entity_id' => $role->id,
        ]);
    }

    public function test_critical_permission_sync_requires_password(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $role = Role::query()->where('name', 'reports_employee')->firstOrFail();
        $ids = Permission::query()
            ->whereIn('name', ['access_dashboard', 'manage_employees'])
            ->pluck('id')
            ->all();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/permissions", [
            'permission_ids' => $ids,
            'version' => $role->version,
            'reason' => 'منح إدارة موظفين',
        ])->assertStatus(422);

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/permissions", [
            'permission_ids' => $ids,
            'version' => $role->version,
            'reason' => 'منح إدارة موظفين',
            'password_confirmation' => 'password',
        ])->assertOk();
    }

    public function test_archive_and_restore_custom_role(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $created = $this->postJson('/api/dashboard/access-control/roles', [
            'name' => 'temp_archive_role',
            'display_name' => 'دور مؤقت',
        ])->assertOk();

        $id = (int) $created->json('data.id');

        $this->patchJson("/api/dashboard/access-control/roles/{$id}/archive", [
            'reason' => 'لم يعد مستخدماً',
        ])->assertOk()->assertJsonPath('data.is_archived', true);

        $this->patchJson("/api/dashboard/access-control/roles/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('data.is_archived', false);
    }
}
