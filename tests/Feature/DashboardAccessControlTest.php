<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Dashboard\Support\PermissionRegistry;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAccessControlTest extends TestCase
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
        return User::factory()->dashboardAdmin('super_admin')->create();
    }

    public function test_guest_receives_401(): void
    {
        $this->getJson('/api/dashboard/access-control/overview')->assertUnauthorized();
    }

    public function test_normal_employee_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('fines_employee')->create());
        $this->getJson('/api/dashboard/access-control/overview')->assertForbidden();
    }

    public function test_manage_employees_without_super_admin_receives_403(): void
    {
        $role = Role::query()->where('name', 'application_manager')->firstOrFail();
        $manageEmployees = Permission::query()->where('name', 'manage_employees')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$manageEmployees->id]);

        $user = User::factory()->dashboardEmployee('application_manager')->create();
        Sanctum::actingAs($user);

        $this->assertTrue($user->hasPermission('manage_employees'));
        $this->getJson('/api/dashboard/access-control/overview')->assertForbidden();
        $this->getJson('/api/dashboard/access-control/roles')->assertForbidden();
    }

    public function test_super_admin_can_access_overview_and_permissions(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/dashboard/access-control/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'total_roles',
                    'total_permissions',
                    'super_admin_count',
                    'document_reviewer_count',
                ],
            ]);

        $permissions = $this->getJson('/api/dashboard/access-control/permissions')->assertOk();
        $groups = $permissions->json('data.groups');
        $this->assertNotEmpty($groups);
        $this->assertArrayHasKey('module_label', $groups[0]);
        $this->assertStringNotContainsString('messages.', (string) $groups[0]['module_label']);
        $this->assertStringNotContainsString('messages.', (string) ($groups[0]['permissions'][0]['label'] ?? ''));
    }

    public function test_citizen_cannot_access_access_control(): void
    {
        Sanctum::actingAs(User::factory()->withApprovedProfile()->create());
        $this->getJson('/api/dashboard/access-control/overview')->assertForbidden();
    }

    public function test_unknown_permission_cannot_be_assigned_to_role(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $role = Role::query()->where('name', 'fines_employee')->firstOrFail();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/permissions", [
            'permission_ids' => [999999],
            'version' => $role->version,
            'reason' => 'محاولة تعيين صلاحية غير موجودة',
        ])->assertStatus(422);
    }

    public function test_no_permission_creation_endpoint_exists(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->postJson('/api/dashboard/access-control/permissions', [
            'name' => 'invented_permission',
        ])->assertStatus(405);
    }

    public function test_registry_contains_known_permissions_only(): void
    {
        $names = PermissionRegistry::permissionNames();
        $this->assertContains('review_documents', $names);
        $this->assertContains('view_applications', $names);
        $this->assertNotContains('*', $names);
    }
}
