<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
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

        $employee->refresh();
        $this->assertSame('updated@test.sy', $employee->email);
        $this->assertSame('reports_employee', $employee->role?->name);
    }

    public function test_super_admin_can_toggle_employee_active(): void
    {
        $this->asSuperAdmin();
        $employee = User::factory()->dashboardEmployee('test_employee')->create(['is_active' => true]);

        $this->patchJson("/api/dashboard/employees/{$employee->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.employee_status_updated'));

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
}
