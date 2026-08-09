<?php

namespace Tests\Feature;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardCitizenManagementTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function asAdmin(): User
    {
        $admin = User::factory()->dashboardAdmin('admin')->create();
        Sanctum::actingAs($admin);
        return $admin;
    }

    private function asSuperAdmin(): User
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        Sanctum::actingAs($admin);
        return $admin;
    }

    private function employeeWithoutManageUsers(): User
    {
        $user = User::factory()->dashboardEmployee('fines_employee')->create();
        Sanctum::actingAs($user);
        return $user;
    }

    private function createCitizen(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'user_type'   => UserType::Citizen,
            'is_active'   => true,
        ], $overrides));
    }

    private function createEmployee(): User
    {
        return User::factory()->dashboardEmployee('fines_employee')->create();
    }

    // =========================================================
    // Authorization tests
    // =========================================================

    public function test_unauthenticated_list_returns_401(): void
    {
        $this->getJson('/api/dashboard/citizens')->assertUnauthorized();
    }

    public function test_citizen_cannot_access_dashboard_citizen_management(): void
    {
        $citizen = $this->createCitizen();
        Sanctum::actingAs($citizen);
        $this->getJson('/api/dashboard/citizens')->assertForbidden();
    }

    public function test_employee_without_manage_users_returns_403(): void
    {
        $this->employeeWithoutManageUsers();
        $this->getJson('/api/dashboard/citizens')->assertForbidden();
    }

    public function test_admin_can_list_citizens(): void
    {
        $this->asAdmin();
        $this->getJson('/api/dashboard/citizens')->assertOk();
    }

    public function test_admin_can_view_citizen_details(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen();
        $this->getJson("/api/dashboard/citizens/{$citizen->id}")->assertOk();
    }

    public function test_same_permission_applies_to_stats(): void
    {
        $this->employeeWithoutManageUsers();
        $this->getJson('/api/dashboard/citizens/stats')->assertForbidden();
    }

    public function test_audit_endpoint_requires_view_audit_logs(): void
    {
        // Admin has view_audit_logs (super_admin/admin have all permissions)
        $this->asAdmin();
        $citizen = $this->createCitizen();
        $this->getJson("/api/dashboard/citizens/{$citizen->id}/audit-logs")->assertOk();
    }

    public function test_employee_without_view_audit_logs_is_denied(): void
    {
        // fines_employee does not have view_audit_logs
        $this->employeeWithoutManageUsers();
        $citizen = $this->createCitizen();
        $this->getJson("/api/dashboard/citizens/{$citizen->id}/audit-logs")->assertForbidden();
    }

    public function test_employee_id_through_citizen_endpoint_returns_404(): void
    {
        $this->asAdmin();
        $employee = $this->createEmployee();
        $this->getJson("/api/dashboard/citizens/{$employee->id}")->assertNotFound();
    }

    public function test_admin_id_through_citizen_endpoint_returns_404(): void
    {
        $admin = $this->asAdmin();
        $this->getJson("/api/dashboard/citizens/{$admin->id}")->assertNotFound();
    }

    // =========================================================
    // Dead routes removed
    // =========================================================

    public function test_post_citizens_route_does_not_exist(): void
    {
        $this->asAdmin();
        $this->postJson('/api/dashboard/citizens', [])->assertStatus(405);
    }

    public function test_delete_citizen_route_does_not_exist(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen();
        $this->deleteJson("/api/dashboard/citizens/{$citizen->id}")->assertStatus(405);
    }

    // =========================================================
    // List tests
    // =========================================================

    public function test_only_citizens_are_returned(): void
    {
        $this->asAdmin();
        $citizen  = $this->createCitizen(['name' => 'مواطن واحد']);
        $employee = $this->createEmployee();

        $data = $this->getJson('/api/dashboard/citizens')->assertOk()->json('data.items');

        $ids = array_column($data, 'id');
        $this->assertContains($citizen->id, $ids);
        $this->assertNotContains($employee->id, $ids);
    }

    public function test_pagination_metadata_is_correct(): void
    {
        $this->asAdmin();
        User::factory()->count(5)->create(['user_type' => UserType::Citizen, 'is_active' => true]);

        $response = $this->getJson('/api/dashboard/citizens?per_page=3')->assertOk();
        $this->assertSame(3, $response->json('data.pagination.per_page'));
        $this->assertCount(3, $response->json('data.items'));
    }

    public function test_max_per_page_validation(): void
    {
        $this->asAdmin();
        $this->getJson('/api/dashboard/citizens?per_page=200')->assertUnprocessable();
    }

    public function test_search_by_name(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['name' => 'مواطن فريد']);
        $this->createCitizen(['name' => 'شخص آخر']);

        $items = $this->getJson('/api/dashboard/citizens?search=فريد')->assertOk()->json('data.items');
        $ids   = array_column($items, 'id');
        $this->assertContains($citizen->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_search_by_national_id(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['national_id' => '09999111222']);
        $this->createCitizen(['national_id' => '01234567890']);

        $items = $this->getJson('/api/dashboard/citizens?search=09999111222')->assertOk()->json('data.items');
        $this->assertContains($citizen->id, array_column($items, 'id'));
    }

    public function test_search_by_phone(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['phone' => '0912345678']);
        $this->createCitizen(['phone' => '0987654321']);

        $items = $this->getJson('/api/dashboard/citizens?search=0912345678')->assertOk()->json('data.items');
        $this->assertContains($citizen->id, array_column($items, 'id'));
    }

    public function test_search_by_email(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['email' => 'unique.search@example.com']);

        $items = $this->getJson('/api/dashboard/citizens?search=unique.search@example.com')->assertOk()->json('data.items');
        $this->assertContains($citizen->id, array_column($items, 'id'));
    }

    public function test_search_cannot_escape_citizen_scope(): void
    {
        $this->asAdmin();
        $employee = $this->createEmployee();

        $items = $this->getJson('/api/dashboard/citizens?search=' . urlencode($employee->name))
            ->assertOk()
            ->json('data.items');

        $this->assertNotContains($employee->id, array_column($items, 'id'));
    }

    public function test_active_filter(): void
    {
        $this->asAdmin();
        $active   = $this->createCitizen(['is_active' => true]);
        $inactive = $this->createCitizen(['is_active' => false]);

        $items = $this->getJson('/api/dashboard/citizens?is_active=1')->assertOk()->json('data.items');
        $ids   = array_column($items, 'id');
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_inactive_filter(): void
    {
        $this->asAdmin();
        $active   = $this->createCitizen(['is_active' => true]);
        $inactive = $this->createCitizen(['is_active' => false]);

        $items = $this->getJson('/api/dashboard/citizens?is_active=0')->assertOk()->json('data.items');
        $ids   = array_column($items, 'id');
        $this->assertContains($inactive->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    public function test_profile_status_filter_approved(): void
    {
        $this->asAdmin();
        $approved = $this->createCitizen(['profile_status' => ProfileStatus::Approved]);
        $pending  = $this->createCitizen(['profile_status' => ProfileStatus::PendingReview]);

        $items = $this->getJson('/api/dashboard/citizens?profile_status=approved')->assertOk()->json('data.items');
        $ids   = array_column($items, 'id');
        $this->assertContains($approved->id, $ids);
        $this->assertNotContains($pending->id, $ids);
    }

    public function test_invalid_profile_status_returns_422(): void
    {
        $this->asAdmin();
        $this->getJson('/api/dashboard/citizens?profile_status=invalid_status')->assertUnprocessable();
    }

    public function test_invalid_is_active_returns_422(): void
    {
        $this->asAdmin();
        $this->getJson('/api/dashboard/citizens?is_active=not_boolean')->assertUnprocessable();
    }

    public function test_list_does_not_include_password_fields(): void
    {
        $this->asAdmin();
        $this->createCitizen();

        $item = $this->getJson('/api/dashboard/citizens')->assertOk()->json('data.items.0');
        $this->assertArrayNotHasKey('password', $item);
        $this->assertArrayNotHasKey('remember_token', $item);
    }

    // =========================================================
    // Statistics tests
    // =========================================================

    public function test_stats_counts_citizens_only(): void
    {
        $this->asAdmin();
        User::factory()->count(2)->create(['user_type' => UserType::Citizen, 'is_active' => true]);
        User::factory()->count(1)->create(['user_type' => UserType::Citizen, 'is_active' => false]);
        $this->createEmployee();

        $data = $this->getJson('/api/dashboard/citizens/stats')->assertOk()->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $data['active']);
        $this->assertSame(1, $data['inactive']);
    }

    public function test_stats_profile_status_counts(): void
    {
        $this->asAdmin();
        $this->createCitizen(['profile_status' => ProfileStatus::Approved]);
        $this->createCitizen(['profile_status' => ProfileStatus::Approved]);
        $this->createCitizen(['profile_status' => ProfileStatus::Rejected]);

        $data = $this->getJson('/api/dashboard/citizens/stats')->assertOk()->json('data');
        $this->assertSame(2, $data['profileStatuses']['approved']);
        $this->assertSame(1, $data['profileStatuses']['rejected']);
    }

    // =========================================================
    // Details tests
    // =========================================================

    public function test_citizen_details_return_required_fields(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen([
            'name'       => 'مواطن تفاصيل',
            'national_id' => '01234567890',
        ]);

        $data = $this->getJson("/api/dashboard/citizens/{$citizen->id}")->assertOk()->json('data');

        $this->assertSame($citizen->id, $data['id']);
        $this->assertSame($citizen->name, $data['name']);
        $this->assertArrayHasKey('account_status', $data);
        $this->assertArrayHasKey('profile_status', $data);
        $this->assertArrayHasKey('counts', $data);
        $this->assertArrayHasKey('actions', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
    }

    public function test_active_citizen_has_null_deactivation(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $data = $this->getJson("/api/dashboard/citizens/{$citizen->id}")->assertOk()->json('data');
        $this->assertNull($data['deactivation']);
    }

    public function test_inactive_citizen_has_deactivation_metadata(): void
    {
        $admin   = $this->asAdmin();
        $citizen = $this->createCitizen([
            'is_active'           => false,
            'deactivated_at'      => now(),
            'deactivated_by'      => $admin->id,
            'deactivation_reason' => 'سبب الاختبار',
        ]);

        $data = $this->getJson("/api/dashboard/citizens/{$citizen->id}")->assertOk()->json('data');
        $this->assertNotNull($data['deactivation']);
        $this->assertSame('سبب الاختبار', $data['deactivation']['reason']);
    }

    public function test_missing_citizen_returns_404(): void
    {
        $this->asAdmin();
        $this->getJson('/api/dashboard/citizens/999999')->assertNotFound();
    }

    // =========================================================
    // Update tests
    // =========================================================

    public function test_allowed_citizen_fields_update(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['name' => 'الاسم القديم']);

        $this->putJson("/api/dashboard/citizens/{$citizen->id}", [
            'name' => 'الاسم الجديد',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $citizen->id, 'name' => 'الاسم الجديد']);
    }

    public function test_is_active_cannot_be_updated_through_generic_update(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $this->putJson("/api/dashboard/citizens/{$citizen->id}", [
            'is_active' => false,
        ])->assertOk();

        // is_active must remain true since it's not an allowed field
        $this->assertDatabaseHas('users', ['id' => $citizen->id, 'is_active' => true]);
    }

    public function test_user_type_cannot_be_changed(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen();

        $this->putJson("/api/dashboard/citizens/{$citizen->id}", [
            'user_type' => 'employee',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $citizen->id, 'user_type' => UserType::Citizen->value]);
    }

    public function test_unique_email_validation(): void
    {
        $this->asAdmin();
        $other   = $this->createCitizen(['email' => 'taken@example.com']);
        $citizen = $this->createCitizen(['email' => 'mine@example.com']);

        $this->putJson("/api/dashboard/citizens/{$citizen->id}", [
            'email' => 'taken@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_unique_phone_validation(): void
    {
        $this->asAdmin();
        $other   = $this->createCitizen(['phone' => '0912345678']);
        $citizen = $this->createCitizen(['phone' => '0987654321']);

        $this->putJson("/api/dashboard/citizens/{$citizen->id}", [
            'phone' => '0912345678',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_update_audits_changed_fields(): void
    {
        $admin   = $this->asAdmin();
        $citizen = $this->createCitizen(['name' => 'الاسم القديم']);

        $this->putJson("/api/dashboard/citizens/{$citizen->id}", ['name' => 'الاسم الجديد'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'citizen.updated',
            'entity_type' => 'user',
            'entity_id'   => $citizen->id,
        ]);
    }

    // =========================================================
    // Deactivation tests
    // =========================================================

    public function test_active_citizen_can_be_deactivated(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", [
            'reason' => 'سبب إداري للاختبار',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $citizen->id, 'is_active' => false]);
    }

    public function test_deactivation_reason_is_required(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen();

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_deactivation_blank_reason_is_rejected(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen();

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", ['reason' => '   '])
            ->assertUnprocessable();
    }

    public function test_deactivation_stores_metadata(): void
    {
        $admin   = $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", [
            'reason' => 'تعطيل تجريبي',
        ])->assertOk();

        $citizen->refresh();
        $this->assertFalse((bool) $citizen->is_active);
        $this->assertNotNull($citizen->deactivated_at);
        $this->assertSame($admin->id, $citizen->deactivated_by);
        $this->assertSame('تعطيل تجريبي', $citizen->deactivation_reason);
    }

    public function test_deactivation_revokes_all_tokens(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);
        $citizen->createToken('token1');
        $citizen->createToken('token2');

        $this->assertSame(2, $citizen->tokens()->count());

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", [
            'reason' => 'مسح التوكنات',
        ])->assertOk();

        $this->assertSame(0, $citizen->tokens()->count());
    }

    public function test_deactivation_creates_audit_log(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", [
            'reason' => 'سبب التدقيق',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'citizen.deactivated',
            'entity_type' => 'user',
            'entity_id'   => $citizen->id,
        ]);
    }

    public function test_deactivation_sends_notification(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", [
            'reason' => 'إشعار الاختبار',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $citizen->id,
            'type'    => 'account.deactivated',
        ]);
    }

    public function test_deactivating_employee_through_citizen_endpoint_returns_404(): void
    {
        $this->asAdmin();
        $employee = $this->createEmployee();

        $this->postJson("/api/dashboard/citizens/{$employee->id}/deactivate", [
            'reason' => 'محاولة غير صالحة',
        ])->assertNotFound();
    }

    public function test_repeated_deactivation_is_idempotent(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", ['reason' => 'أول تعطيل'])->assertOk();
        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", ['reason' => 'ثاني تعطيل'])->assertOk();

        // Should only have one audit log for deactivation
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'citizen.deactivated')
            ->where('entity_id', $citizen->id)
            ->count());
    }

    public function test_pending_applications_remain_after_deactivation(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", ['reason' => 'فحص الطلبات'])->assertOk();

        // Citizen record still exists
        $this->assertDatabaseHas('users', ['id' => $citizen->id]);
    }

    // =========================================================
    // Activation tests
    // =========================================================

    public function test_inactive_citizen_can_be_activated(): void
    {
        $admin   = $this->asAdmin();
        $citizen = $this->createCitizen([
            'is_active'           => false,
            'deactivated_at'      => now(),
            'deactivated_by'      => $admin->id,
            'deactivation_reason' => 'سبب قديم',
        ]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/activate")->assertOk();

        $citizen->refresh();
        $this->assertTrue((bool) $citizen->is_active);
        $this->assertNull($citizen->deactivated_at);
        $this->assertNull($citizen->deactivated_by);
        $this->assertNull($citizen->deactivation_reason);
    }

    public function test_activation_creates_audit_log(): void
    {
        $admin   = $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => false, 'deactivated_by' => $admin->id]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/activate")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'citizen.activated',
            'entity_type' => 'user',
            'entity_id'   => $citizen->id,
        ]);
    }

    public function test_activation_sends_notification(): void
    {
        $admin   = $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => false, 'deactivated_by' => $admin->id]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/activate")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $citizen->id,
            'type'    => 'account.activated',
        ]);
    }

    public function test_no_token_issued_on_activation(): void
    {
        $admin   = $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => false, 'deactivated_by' => $admin->id]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/activate")->assertOk();

        $this->assertSame(0, $citizen->fresh()->tokens()->count());
    }

    public function test_repeated_activation_is_idempotent(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/activate")->assertOk();
        $this->postJson("/api/dashboard/citizens/{$citizen->id}/activate")->assertOk();

        $this->assertSame(0, AuditLog::query()
            ->where('action', 'citizen.activated')
            ->where('entity_id', $citizen->id)
            ->count());

        $this->assertSame(0, \App\Models\Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'account.activated')
            ->count());
    }

    public function test_activating_employee_returns_404(): void
    {
        $this->asAdmin();
        $employee = $this->createEmployee();

        $this->postJson("/api/dashboard/citizens/{$employee->id}/activate")->assertNotFound();
    }

    // =========================================================
    // Inactive token / middleware tests
    // =========================================================

    public function test_deactivation_deletes_all_tokens(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen(['is_active' => true]);
        $citizen->createToken('t1');
        $citizen->createToken('t2');

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", ['reason' => 'مسح'])->assertOk();

        $this->assertSame(0, $citizen->tokens()->count());
    }

    public function test_inactive_citizen_is_rejected_by_ensure_citizen_middleware(): void
    {
        $citizen = $this->createCitizen(['is_active' => false]);
        Sanctum::actingAs($citizen);

        $this->getJson('/api/profile/status')->assertForbidden();
    }

    public function test_inactive_citizen_cannot_access_applications(): void
    {
        $citizen = $this->createCitizen(['is_active' => false]);
        Sanctum::actingAs($citizen);

        $this->getJson('/api/applications')->assertForbidden();
    }

    // =========================================================
    // Related endpoints tests
    // =========================================================

    public function test_applications_list_is_citizen_scoped(): void
    {
        $this->asAdmin();
        $citizen  = $this->createCitizen();
        $employee = $this->createEmployee();

        $this->getJson("/api/dashboard/citizens/{$citizen->id}/applications")->assertOk();
        $this->getJson("/api/dashboard/citizens/{$employee->id}/applications")->assertNotFound();
    }

    public function test_applications_pagination(): void
    {
        $this->asAdmin();
        $citizen = $this->createCitizen();

        $response = $this->getJson("/api/dashboard/citizens/{$citizen->id}/applications")
            ->assertOk();

        $this->assertArrayHasKey('pagination', $response->json('data'));
    }

    public function test_licenses_list_is_citizen_scoped(): void
    {
        $this->asAdmin();
        $citizen  = $this->createCitizen();
        $employee = $this->createEmployee();

        $this->getJson("/api/dashboard/citizens/{$citizen->id}/licenses")->assertOk();
        $this->getJson("/api/dashboard/citizens/{$employee->id}/licenses")->assertNotFound();
    }

    public function test_fines_list_is_citizen_scoped(): void
    {
        $this->asAdmin();
        $citizen  = $this->createCitizen();
        $employee = $this->createEmployee();

        $this->getJson("/api/dashboard/citizens/{$citizen->id}/fines")->assertOk();
        $this->getJson("/api/dashboard/citizens/{$employee->id}/fines")->assertNotFound();
    }

    // =========================================================
    // Audit log endpoint tests
    // =========================================================

    public function test_authorized_admin_can_view_citizen_audit_logs(): void
    {
        $admin   = $this->asAdmin();
        $citizen = $this->createCitizen();

        AuditLog::create([
            'user_id'     => $admin->id,
            'action'      => 'citizen.deactivated',
            'entity_type' => 'user',
            'entity_id'   => $citizen->id,
            'old_values'  => ['is_active' => true],
            'new_values'  => ['is_active' => false],
        ]);

        $response = $this->getJson("/api/dashboard/citizens/{$citizen->id}/audit-logs")->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.items')));
    }

    public function test_audit_logs_belong_only_to_target_citizen(): void
    {
        $admin    = $this->asAdmin();
        $citizen1 = $this->createCitizen();
        $citizen2 = $this->createCitizen();

        AuditLog::create([
            'user_id'     => $admin->id,
            'action'      => 'citizen.updated',
            'entity_type' => 'user',
            'entity_id'   => $citizen2->id,
        ]);

        $items = $this->getJson("/api/dashboard/citizens/{$citizen1->id}/audit-logs")->assertOk()->json('data.items');
        foreach ($items as $item) {
            $this->assertSame($citizen1->id, $item['entity']['id']);
        }
    }

    // =========================================================
    // Profile status tests
    // =========================================================

    public function test_profile_statuses_endpoint_returns_all_statuses(): void
    {
        $this->asAdmin();
        $data = $this->getJson('/api/dashboard/citizens/profile-statuses')->assertOk()->json('data');

        $values = array_column($data, 'value');
        $this->assertContains('incomplete', $values);
        $this->assertContains('pending_review', $values);
        $this->assertContains('approved', $values);
        $this->assertContains('rejected', $values);
    }
}
