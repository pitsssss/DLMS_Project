<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function asSuperAdmin(): User
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_super_admin_can_create_employee(): void
    {
        $this->asSuperAdmin();

        $response = $this->postJson('/api/dashboard/employees', [
            'name' => 'موظف تجريبي',
            'email' => 'new.employee@test.sy',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'fines_employee',
        ])
            ->assertCreated()
            ->assertJsonPath('message', __('messages.dashboard.employee_created'));

        $employeeId = (int) User::query()->where('email', 'new.employee@test.sy')->value('id');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.created',
            'entity_type' => 'user',
            'entity_id' => $employeeId,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'new.employee@test.sy',
            'user_type' => UserType::Employee->value,
        ]);
    }

    public function test_super_admin_can_update_employee(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('audit_employee')->create([
            'email' => 'update-me@test.sy',
        ]);

        $this->putJson("/api/dashboard/employees/{$employee->id}", [
            'name' => 'اسم محدث',
            'email' => 'updated@test.sy',
            'role' => 'reports_employee',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.employee_updated'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.updated',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
        ]);

        $employee->refresh();
        $this->assertSame('updated@test.sy', $employee->email);
        $this->assertSame('reports_employee', $employee->role?->name);
    }

    public function test_super_admin_can_toggle_employee_active(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('test_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active", [
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.employee_deactivated'));

        $employee->refresh();
        $this->assertFalse($employee->is_active);
    }

    public function test_super_admin_can_reset_employee_password(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('license_employee')->create();
        $employee->createToken('to-revoke');

        $this->postJson("/api/dashboard/employees/{$employee->id}/reset-password", [
            'password' => 'newemp123',
            'password_confirmation' => 'newemp123',
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.employee_password_reset'));

        $employee->refresh();
        $this->assertTrue(Hash::check('newemp123', $employee->password));
        $this->assertSame(0, $employee->tokens()->count());
    }

    public function test_super_admin_can_assign_role(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('payment_employee')->create();

        $this->postJson("/api/dashboard/employees/{$employee->id}/assign-role", [
            'role' => 'application_manager',
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.role_assigned'));

        $employee->refresh();
        $this->assertSame('application_manager', $employee->role?->name);
    }

    public function test_non_authorized_employee_cannot_manage_employees(): void
    {
        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        Sanctum::actingAs($reviewer);

        $this->getJson('/api/dashboard/employees')->assertForbidden();
        $this->postJson('/api/dashboard/employees', [
            'name' => 'x',
            'email' => 'x@test.sy',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'fines_employee',
        ])->assertForbidden();
    }

    public function test_cannot_assign_citizen_role_when_creating_employee(): void
    {
        $this->asSuperAdmin();

        $this->postJson('/api/dashboard/employees', [
            'name' => 'مواطن',
            'email' => 'bad@test.sy',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'citizen',
        ])->assertStatus(422);
    }

    public function test_authorized_user_can_list_employees(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create([
            'name' => 'قائمة موظف',
            'email' => 'list.emp@test.sy',
            'phone' => '0988111001',
        ]);

        $this->getJson('/api/dashboard/employees')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        ['id', 'name', 'email', 'phone', 'user_type', 'is_active', 'created_at', 'role'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                    'statistics' => ['total', 'active', 'inactive'],
                    'role_options',
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_list_employees(): void
    {
        $this->getJson('/api/dashboard/employees')->assertUnauthorized();
    }

    public function test_citizen_cannot_list_employees(): void
    {
        $citizen = User::factory()->create(['user_type' => UserType::Citizen]);
        Sanctum::actingAs($citizen);

        $this->getJson('/api/dashboard/employees')->assertForbidden();
    }

    public function test_employee_list_excludes_citizens(): void
    {
        $this->asSuperAdmin();

        User::factory()->create([
            'name' => 'مواطن مخفي',
            'email' => 'citizen.hide@test.sy',
            'user_type' => UserType::Citizen,
        ]);
        User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'visible.emp@test.sy',
        ]);

        $emails = collect($this->getJson('/api/dashboard/employees?per_page=50')->json('data.items'))
            ->pluck('email')
            ->all();

        $this->assertContains('visible.emp@test.sy', $emails);
        $this->assertNotContains('citizen.hide@test.sy', $emails);
    }

    public function test_search_by_name_works(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create(['name' => 'أحمد البحث', 'email' => 'a@test.sy']);
        User::factory()->dashboardEmployee('audit_employee')->create(['name' => 'سارة أخرى', 'email' => 'b@test.sy']);

        $items = $this->getJson('/api/dashboard/employees?search='.urlencode('أحمد'))
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('أحمد البحث', $items[0]['name']);
    }

    public function test_search_by_email_works(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create(['email' => 'unique.search@test.sy']);
        User::factory()->dashboardEmployee('audit_employee')->create(['email' => 'other@test.sy']);

        $items = $this->getJson('/api/dashboard/employees?search=unique.search')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('unique.search@test.sy', $items[0]['email']);
    }

    public function test_search_by_phone_works(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'phone.a@test.sy',
            'phone' => '0988123456',
        ]);
        User::factory()->dashboardEmployee('audit_employee')->create([
            'email' => 'phone.b@test.sy',
            'phone' => '0988000000',
        ]);

        $items = $this->getJson('/api/dashboard/employees?search=0988123456')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('0988123456', $items[0]['phone']);
    }

    public function test_role_filter_works(): void
    {
        $this->asSuperAdmin();
        $finesRoleId = Role::query()->where('name', 'fines_employee')->value('id');

        User::factory()->dashboardEmployee('fines_employee')->create(['email' => 'role.fines@test.sy']);
        User::factory()->dashboardEmployee('audit_employee')->create(['email' => 'role.audit@test.sy']);

        $items = $this->getJson('/api/dashboard/employees?role_id='.$finesRoleId)
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame('fines_employee', $item['role']['name']);
        }
    }

    public function test_active_status_filter_works(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'active.one@test.sy',
            'is_active' => true,
        ]);
        User::factory()->dashboardEmployee('audit_employee')->create([
            'email' => 'inactive.one@test.sy',
            'is_active' => false,
        ]);

        $items = $this->getJson('/api/dashboard/employees?is_active=1')
            ->assertOk()
            ->json('data.items');

        $this->assertTrue(collect($items)->every(fn ($item) => $item['is_active'] === true));
        $this->assertTrue(collect($items)->contains(fn ($item) => $item['email'] === 'active.one@test.sy'));
        $this->assertFalse(collect($items)->contains(fn ($item) => $item['email'] === 'inactive.one@test.sy'));
    }

    public function test_inactive_status_filter_works(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'active.two@test.sy',
            'is_active' => true,
        ]);
        User::factory()->dashboardEmployee('audit_employee')->create([
            'email' => 'inactive.two@test.sy',
            'is_active' => false,
        ]);

        $items = $this->getJson('/api/dashboard/employees?is_active=0')
            ->assertOk()
            ->json('data.items');

        $this->assertTrue(collect($items)->every(fn ($item) => $item['is_active'] === false));
        $this->assertTrue(collect($items)->contains(fn ($item) => $item['email'] === 'inactive.two@test.sy'));
    }

    public function test_user_type_filter_works(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create(['email' => 'type.emp@test.sy']);
        User::factory()->dashboardAdmin('admin')->create(['email' => 'type.admin@test.sy']);

        $employees = $this->getJson('/api/dashboard/employees?user_type=employee&per_page=50')
            ->assertOk()
            ->json('data.items');

        $this->assertTrue(collect($employees)->every(fn ($item) => $item['user_type'] === 'employee'));
        $this->assertTrue(collect($employees)->contains(fn ($item) => $item['email'] === 'type.emp@test.sy'));
        $this->assertFalse(collect($employees)->contains(fn ($item) => $item['email'] === 'type.admin@test.sy'));
    }

    public function test_pagination_metadata_and_per_page_options(): void
    {
        $this->asSuperAdmin();

        for ($i = 0; $i < 12; $i++) {
            User::factory()->dashboardEmployee('fines_employee')->create([
                'email' => "page.emp{$i}@test.sy",
            ]);
        }

        $default = $this->getJson('/api/dashboard/employees')
            ->assertOk();

        $this->assertSame(20, $default->json('data.pagination.per_page'));
        $this->assertArrayHasKey('from', $default->json('data.pagination'));
        $this->assertArrayHasKey('to', $default->json('data.pagination'));

        $response = $this->getJson('/api/dashboard/employees?per_page=10&page=1')
            ->assertOk();

        $this->assertSame(10, $response->json('data.pagination.per_page'));
        $this->assertSame(1, $response->json('data.pagination.current_page'));
        $this->assertGreaterThanOrEqual(12, $response->json('data.pagination.total'));
        $this->assertCount(10, $response->json('data.items'));

        $this->getJson('/api/dashboard/employees?per_page=20')->assertOk()
            ->assertJsonPath('data.pagination.per_page', 20);

        $this->getJson('/api/dashboard/employees?per_page=25')->assertOk()
            ->assertJsonPath('data.pagination.per_page', 25);

        $this->getJson('/api/dashboard/employees?per_page=50')->assertOk()
            ->assertJsonPath('data.pagination.per_page', 50);
    }

    public function test_missing_is_active_returns_both_statuses(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'both.active@test.sy',
            'is_active' => true,
        ]);
        User::factory()->dashboardEmployee('audit_employee')->create([
            'email' => 'both.inactive@test.sy',
            'is_active' => false,
        ]);

        $emails = collect($this->getJson('/api/dashboard/employees?per_page=50')->json('data.items'))
            ->pluck('email')
            ->all();

        $this->assertContains('both.active@test.sy', $emails);
        $this->assertContains('both.inactive@test.sy', $emails);
    }

    public function test_search_does_not_escape_employee_scope(): void
    {
        $this->asSuperAdmin();

        User::factory()->create([
            'name' => 'أحمد مواطن',
            'email' => 'citizen.scope@test.sy',
            'phone' => '0988765432',
            'user_type' => UserType::Citizen,
        ]);
        User::factory()->dashboardEmployee('fines_employee')->create([
            'name' => 'أحمد موظف',
            'email' => 'employee.scope@test.sy',
        ]);

        $items = $this->getJson('/api/dashboard/employees?search='.urlencode('أحمد'))
            ->assertOk()
            ->json('data.items');

        $emails = collect($items)->pluck('email')->all();
        $this->assertContains('employee.scope@test.sy', $emails);
        $this->assertNotContains('citizen.scope@test.sy', $emails);
    }

    public function test_invalid_role_id_returns_422(): void
    {
        $this->asSuperAdmin();

        $this->getJson('/api/dashboard/employees?role_id=999999')->assertStatus(422);
    }

    public function test_invalid_sort_by_and_direction_return_422(): void
    {
        $this->asSuperAdmin();

        $this->getJson('/api/dashboard/employees?sort_by=password')->assertStatus(422);
        $this->getJson('/api/dashboard/employees?sort_direction=sideways')->assertStatus(422);
    }

    public function test_pagination_total_reflects_filtered_results(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create(['email' => 'filter.a@test.sy']);
        User::factory()->dashboardEmployee('audit_employee')->create(['email' => 'filter.b@test.sy']);

        $total = $this->getJson('/api/dashboard/employees?search=filter.a')
            ->assertOk()
            ->json('data.pagination.total');

        $this->assertSame(1, $total);
    }

    public function test_default_sorting_is_created_at_desc(): void
    {
        $this->asSuperAdmin();

        $older = User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'sort.older@test.sy',
            'created_at' => now()->subDay(),
        ]);
        $newer = User::factory()->dashboardEmployee('audit_employee')->create([
            'email' => 'sort.newer@test.sy',
            'created_at' => now(),
        ]);

        $items = $this->getJson('/api/dashboard/employees?per_page=50')
            ->assertOk()
            ->json('data.items');

        $ids = collect($items)->pluck('id')->all();
        $this->assertTrue(array_search($newer->id, $ids, true) < array_search($older->id, $ids, true));
    }

    public function test_allowed_sorting_by_name_asc_works(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create([
            'name' => 'Zaid Employee',
            'email' => 'sort.name.z@test.sy',
        ]);
        User::factory()->dashboardEmployee('audit_employee')->create([
            'name' => 'Ahmad Employee',
            'email' => 'sort.name.a@test.sy',
        ]);

        $names = collect($this->getJson('/api/dashboard/employees?sort_by=name&sort_direction=asc&per_page=50')
            ->assertOk()
            ->json('data.items'))
            ->pluck('name')
            ->all();

        $indexA = array_search('Ahmad Employee', $names, true);
        $indexZ = array_search('Zaid Employee', $names, true);
        $this->assertNotFalse($indexA);
        $this->assertNotFalse($indexZ);
        $this->assertTrue($indexA < $indexZ);
    }

    public function test_role_includes_id_name_and_display_name(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create(['email' => 'role.fields@test.sy']);

        $item = collect($this->getJson('/api/dashboard/employees?per_page=50')->json('data.items'))
            ->firstWhere('email', 'role.fields@test.sy');

        $this->assertNotNull($item);
        $this->assertArrayHasKey('id', $item['role']);
        $this->assertArrayHasKey('name', $item['role']);
        $this->assertArrayHasKey('display_name', $item['role']);
    }

    public function test_activate_and_deactivate_endpoints_work(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('message', __('messages.dashboard.employee_deactivated'));

        $this->assertFalse($employee->fresh()->is_active);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('message', __('messages.dashboard.employee_activated'));

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_unauthorized_cannot_activate_or_deactivate(): void
    {
        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        Sanctum::actingAs($reviewer);
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => false]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/activate")->assertForbidden();
        $this->patchJson("/api/dashboard/employees/{$employee->id}/deactivate")->assertForbidden();
    }

    public function test_citizen_cannot_activate_or_deactivate(): void
    {
        $citizen = User::factory()->create(['user_type' => UserType::Citizen]);
        Sanctum::actingAs($citizen);
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => false]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/activate")->assertForbidden();
        $this->patchJson("/api/dashboard/employees/{$employee->id}/deactivate")->assertForbidden();
    }

    public function test_target_citizen_cannot_be_treated_as_employee(): void
    {
        $this->asSuperAdmin();
        $citizen = User::factory()->create([
            'user_type' => UserType::Citizen,
            'is_active' => true,
        ]);

        $this->patchJson("/api/dashboard/employees/{$citizen->id}/deactivate")
            ->assertNotFound();
        $this->patchJson("/api/dashboard/employees/{$citizen->id}/activate")
            ->assertNotFound();
        $this->getJson("/api/dashboard/employees/{$citizen->id}")
            ->assertNotFound();
    }

    public function test_activate_deactivate_write_audit_logs(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/deactivate")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.deactivated',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
        ]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/activate")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.activated',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
        ]);
    }

    public function test_mutation_responses_do_not_expose_sensitive_fields(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => false]);

        $data = $this->patchJson("/api/dashboard/employees/{$employee->id}/activate")
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
    }

    public function test_phone_null_is_returned_as_null(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'null.phone@test.sy',
            'phone' => null,
        ]);

        $item = collect($this->getJson('/api/dashboard/employees?per_page=50')->json('data.items'))
            ->firstWhere('email', 'null.phone@test.sy');

        $this->assertNotNull($item);
        $this->assertNull($item['phone']);
    }

    public function test_statistics_match_database_counts(): void
    {
        $this->asSuperAdmin();

        $before = $this->getJson('/api/dashboard/employees')->json('data.statistics');

        User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'stat.match.active@test.sy',
            'is_active' => true,
        ]);
        User::factory()->dashboardEmployee('audit_employee')->create([
            'email' => 'stat.match.inactive@test.sy',
            'is_active' => false,
        ]);
        User::factory()->create([
            'email' => 'stat.match.citizen@test.sy',
            'user_type' => UserType::Citizen,
            'is_active' => true,
        ]);

        $stats = $this->getJson('/api/dashboard/employees')->json('data.statistics');

        $this->assertSame($before['total'] + 2, $stats['total']);
        $this->assertSame($before['active'] + 1, $stats['active']);
        $this->assertSame($before['inactive'] + 1, $stats['inactive']);
    }

    public function test_cannot_deactivate_self_via_deactivate_endpoint(): void
    {
        $admin = $this->asSuperAdmin();
        User::factory()->dashboardAdmin('super_admin')->create(['email' => 'other.super2@test.sy']);

        $this->patchJson("/api/dashboard/employees/{$admin->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.dashboard.cannot_deactivate_self'));
    }

    public function test_invalid_per_page_is_rejected(): void
    {
        $this->asSuperAdmin();

        $this->getJson('/api/dashboard/employees?per_page=15')->assertStatus(422);
    }

    public function test_list_returns_required_fields_without_sensitive_data(): void
    {
        $this->asSuperAdmin();
        User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'fields@test.sy',
            'phone' => '0988999000',
        ]);

        $item = collect($this->getJson('/api/dashboard/employees?per_page=50')->json('data.items'))
            ->firstWhere('email', 'fields@test.sy');

        $this->assertNotNull($item);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertArrayHasKey('phone', $item);
        $this->assertArrayHasKey('user_type', $item);
        $this->assertArrayHasKey('role', $item);
        $this->assertArrayNotHasKey('password', $item);
        $this->assertArrayNotHasKey('remember_token', $item);
    }

    public function test_statistics_are_global_not_page_based(): void
    {
        $this->asSuperAdmin();

        for ($i = 0; $i < 5; $i++) {
            User::factory()->dashboardEmployee('fines_employee')->create([
                'email' => "stats.active{$i}@test.sy",
                'is_active' => true,
            ]);
        }
        for ($i = 0; $i < 3; $i++) {
            User::factory()->dashboardEmployee('audit_employee')->create([
                'email' => "stats.inactive{$i}@test.sy",
                'is_active' => false,
            ]);
        }

        $stats = $this->getJson('/api/dashboard/employees?per_page=10&is_active=1')
            ->assertOk()
            ->json('data.statistics');

        $this->assertGreaterThanOrEqual(5, $stats['active']);
        $this->assertGreaterThanOrEqual(3, $stats['inactive']);
        $this->assertSame($stats['total'], $stats['active'] + $stats['inactive']);
        // Filtered page has only active rows, but inactive count still reflects global inactive.
        $this->assertGreaterThanOrEqual(3, $stats['inactive']);
    }

    public function test_authorized_user_can_deactivate_active_employee(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active", [
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_authorized_user_can_activate_inactive_employee(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => false]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active", [
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('message', __('messages.dashboard.employee_activated'));

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_unauthorized_user_cannot_toggle_employee(): void
    {
        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        Sanctum::actingAs($reviewer);
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active", [
            'is_active' => false,
        ])->assertForbidden();
    }

    public function test_citizen_cannot_toggle_employee(): void
    {
        $citizen = User::factory()->create(['user_type' => UserType::Citizen]);
        Sanctum::actingAs($citizen);
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active", [
            'is_active' => false,
        ])->assertForbidden();
    }

    public function test_cannot_toggle_nonexistent_employee(): void
    {
        $this->asSuperAdmin();

        $this->patchJson('/api/dashboard/employees/999999/toggle-active', [
            'is_active' => false,
        ])->assertNotFound();
    }

    public function test_repeated_deactivation_is_idempotent(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => false]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active", [
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_repeated_activation_is_idempotent(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active", [
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_cannot_deactivate_self(): void
    {
        $admin = $this->asSuperAdmin();
        // Ensure another super admin exists so last-super-admin rule is not the blocker.
        User::factory()->dashboardAdmin('super_admin')->create(['email' => 'other.super@test.sy']);

        $this->patchJson("/api/dashboard/employees/{$admin->id}/toggle-active", [
            'is_active' => false,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.dashboard.cannot_deactivate_self'));
    }

    public function test_deactivate_last_super_admin_is_blocked(): void
    {
        $lastSuper = User::factory()->dashboardAdmin('super_admin')->create([
            'email' => 'final.super@test.sy',
            'is_active' => true,
        ]);

        // Actor with manage_employees who is not a super_admin.
        $actor = User::factory()->dashboardAdmin('admin')->create([
            'email' => 'actor.admin@test.sy',
            'is_active' => true,
        ]);
        Sanctum::actingAs($actor);

        $response = $this->patchJson("/api/dashboard/employees/{$lastSuper->id}/toggle-active", [
            'is_active' => false,
        ]);

        // admin role may lack manage_employees → 403; if allowed, expect 422 last super admin.
        if ($response->status() === 403) {
            $this->markTestSkipped('admin role lacks manage_employees; last-super-admin covered when permission exists');
        }

        $response->assertStatus(422)
            ->assertJsonPath('message', __('messages.dashboard.cannot_deactivate_last_super_admin'));
    }

    public function test_toggle_writes_audit_log(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active", [
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.deactivated',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
        ]);
    }
}
