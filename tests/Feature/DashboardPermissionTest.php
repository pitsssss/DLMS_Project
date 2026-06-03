<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardPermissionTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function bearer(User $user): void
    {
        Sanctum::actingAs($user);
    }

    public function test_super_admin_can_access_protected_admin_routes(): void
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        $this->bearer($admin);

        AuditLog::query()->create([
            'user_id' => null,
            'action' => 'dashboard.test',
            'entity_type' => 'test',
            'entity_id' => 1,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->getJson('/api/admin/audit-logs')->assertOk();
        $this->getJson('/api/admin/reports/overview')->assertOk();
        $this->getJson('/api/admin/fines')->assertOk();
        $this->getJson('/api/admin/profile-reviews')->assertOk();
    }

    public function test_profile_reviewer_can_access_profile_review_apis(): void
    {
        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        $this->bearer($reviewer);

        $this->getJson('/api/admin/profile-reviews')->assertOk();
    }

    public function test_profile_reviewer_cannot_manage_employees(): void
    {
        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        $this->bearer($reviewer);

        $this->getJson('/api/dashboard/employees')->assertForbidden();
    }

    public function test_fines_employee_can_access_fines_apis(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create();
        $this->bearer($employee);

        $this->getJson('/api/admin/fines')->assertOk();
    }

    public function test_fines_employee_cannot_access_audit_logs(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create();
        $this->bearer($employee);

        $this->getJson('/api/admin/audit-logs')->assertForbidden();
    }

    public function test_audit_employee_can_access_audit_logs(): void
    {
        $employee = User::factory()->dashboardEmployee('audit_employee')->create();
        $this->bearer($employee);

        $this->getJson('/api/admin/audit-logs')->assertOk();
    }

    public function test_reports_employee_can_access_reports(): void
    {
        $employee = User::factory()->dashboardEmployee('reports_employee')->create();
        $this->bearer($employee);

        $this->getJson('/api/admin/reports/overview')->assertOk();
    }

    public function test_employee_without_permission_gets_403(): void
    {
        $employee = User::factory()->dashboardEmployee('settings_employee')->create();
        $this->bearer($employee);

        $this->getJson('/api/admin/fines')->assertForbidden();
    }

    public function test_super_admin_bypasses_permission_checks(): void
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        $this->bearer($admin);

        $this->getJson('/api/dashboard/roles')->assertOk();
        $this->getJson('/api/dashboard/employees')->assertOk();
    }
}
