<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Dashboard\Services\RbacBootstrapService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_last_super_admin_role_cannot_be_removed(): void
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create(['password' => 'password']);
        Sanctum::actingAs($admin);

        $fines = Role::query()->where('name', 'fines_employee')->firstOrFail();

        $this->patchJson("/api/dashboard/employees/{$admin->id}/roles", [
            'role_id' => $fines->id,
            'reason' => 'محاولة إزالة آخر مدير',
            'password_confirmation' => 'password',
        ])->assertStatus(422);
    }

    public function test_protected_role_cannot_be_archived(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin('super_admin')->create());
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/archive", [
            'reason' => 'محاولة أرشفة',
        ])->assertStatus(422);
    }

    public function test_bootstrap_is_idempotent_and_does_not_overwrite_role_permissions(): void
    {
        $role = Role::query()->where('name', 'fines_employee')->firstOrFail();
        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        // Mutate away from baseline.
        $role->permissions()->sync(
            \App\Models\Permission::query()->where('name', 'access_dashboard')->pluck('id')
        );

        Artisan::call('rbac:bootstrap');
        $afterBootstrap = $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame(['access_dashboard'], $afterBootstrap);

        // Re-seed should not restore old manage_fines either.
        $this->seed(PermissionsSeeder::class);
        $afterSeed = $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame(['access_dashboard'], $afterSeed);

        // Restore for isolation of other assertions if needed.
        $ids = \App\Models\Permission::query()->whereIn('name', $before)->pluck('id');
        $role->permissions()->sync($ids);
    }

    public function test_document_reviewer_repair_dry_run_and_apply(): void
    {
        $role = Role::query()->where('name', 'profile_document_reviewer')->firstOrFail();
        $viewApps = \App\Models\Permission::query()->where('name', 'view_applications')->firstOrFail();
        $role->permissions()->attach($viewApps->id);

        $rbac = app(RbacBootstrapService::class);
        $dry = $rbac->repairDocumentReviewer(false);
        $this->assertTrue($dry['changed']);
        $this->assertContains('view_applications', $role->fresh()->permissions()->pluck('name')->all());

        $apply = $rbac->repairDocumentReviewer(true);
        $this->assertTrue($apply['changed']);
        $this->assertNotContains('view_applications', $role->fresh()->permissions()->pluck('name')->all());
        $this->assertTrue($apply['super_admin_untouched']);
    }

    public function test_password_confirmation_value_is_not_logged(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin('super_admin')->create(['password' => 'password']));
        $role = Role::query()->where('name', 'reports_employee')->firstOrFail();
        $ids = \App\Models\Permission::query()
            ->whereIn('name', ['access_dashboard', 'manage_settings'])
            ->pluck('id')
            ->all();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/permissions", [
            'permission_ids' => $ids,
            'version' => $role->version,
            'reason' => 'اختبار عدم تسجيل كلمة المرور',
            'password_confirmation' => 'password',
        ])->assertOk();

        $log = \App\Models\AuditLog::query()
            ->where('action', 'access_role.permissions_updated')
            ->latest('id')
            ->first();

        $encoded = json_encode($log?->new_values)." ".json_encode($log?->old_values);
        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertStringNotContainsString('"password_confirmation"', $encoded);
    }
}
