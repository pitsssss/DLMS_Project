<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Dashboard\Services\VisitorAccountProvisioner;
use Database\Seeders\VisitorAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class VisitorReadOnlyAccessTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->fakeSuccessfulBrevoTransactionalEmail();
        config([
            'demo.visitor.enabled' => true,
            'demo.visitor.email' => 'visitor@syrtak.demo',
            'demo.visitor.password' => 'Syrtak@Visitor2026!',
            'demo.visitor.name' => 'زائر تجريبي',
            'demo.visitor.phone' => '0900000000',
        ]);
    }

    public function test_visitor_login_succeeds_and_reports_read_only_role(): void
    {
        $visitor = $this->visitor();

        $response = $this->postJson('/api/dashboard/auth/login', [
            'email' => $visitor->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role.name', 'visitor')
            ->assertJsonPath('data.user.user_type', 'employee')
            ->assertJsonPath('data.user.is_visitor', true)
            ->assertJsonPath('data.user.is_read_only', true)
            ->assertJsonPath('data.user.is_super_admin', false);

        $this->assertNotEmpty($response->json('data.token'));
        $permissions = $response->json('data.user.permissions');
        $this->assertContains('access_dashboard', $permissions);
        $this->assertContains('view_applications', $permissions);
        $this->assertNotContains('manage_employees', $permissions);
        $this->assertNotContains('manage_users', $permissions);
        $this->assertNotContains('*', $permissions);

        $modules = collect($response->json('data.user.dashboard_modules'))->pluck('key');
        $this->assertTrue($modules->contains('overview'));
        $this->assertTrue($modules->contains('applications'));
        $this->assertTrue($modules->contains('citizens'));
        $this->assertFalse($modules->contains('employees'));
        $this->assertFalse($modules->contains('audit_logs'));
    }

    public function test_invalid_visitor_password_is_rejected(): void
    {
        $visitor = $this->visitor();

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $visitor->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertJsonPath('message', __('messages.dashboard.invalid_credentials'));
    }

    public function test_disabled_visitor_cannot_login(): void
    {
        config(['demo.visitor.enabled' => false]);
        $visitor = $this->visitor();

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $visitor->email,
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', __('messages.visitor.unavailable'));

        $token = $visitor->createToken('dashboard-token')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/overview')
            ->assertForbidden()
            ->assertJsonPath('message', __('messages.visitor.unavailable'));
    }

    public function test_employee_and_administrator_login_remain_unchanged(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'fines-visitor-regression@test.sy',
            'password' => Hash::make('password123'),
        ]);
        $admin = User::factory()->dashboardAdmin('super_admin')->create([
            'email' => 'admin-visitor-regression@test.sy',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $employee->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user.role.name', 'fines_employee')
            ->assertJsonPath('data.user.is_visitor', false)
            ->assertJsonPath('data.user.is_read_only', false);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user.role.name', 'super_admin')
            ->assertJsonPath('data.user.is_super_admin', true)
            ->assertJsonPath('data.user.is_visitor', false);

        $employeeToken = $this->dashboardLoginAs($employee, 'password123');
        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/admin/fines', [])
            ->assertStatus(422);
    }

    public function test_visitor_can_browse_safe_reads_with_filters_and_pagination(): void
    {
        [$citizen, $application] = $this->privateApplication();
        $token = $this->dashboardLoginAs($this->visitor());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('success', true);

        $list = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/applications?per_page=5&page=1&status=draft&search='.$application->application_number)
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.pagination.current_page', 1);

        $this->assertSame($application->application_number, $list->json('data.items.0.application_number'));
        $this->assertStringNotContainsString($citizen->name, $list->getContent());
        $this->assertStringNotContainsString($citizen->phone, $list->getContent());

        $detail = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/applications/'.$application->application_number)
            ->assertOk();

        $this->assertSame([], $detail->json('data.audit_logs'));
        $this->assertStringNotContainsString($citizen->national_id, $detail->getContent());
        $this->assertStringNotContainsString($citizen->email, $detail->getContent());
        $this->assertStringNotContainsString($citizen->name, $detail->getContent());
        $this->assertStringNotContainsString($citizen->phone, $detail->getContent());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/reports/options')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/fines?per_page=5&status=unpaid')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 5);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/license-types')
            ->assertOk();
    }

    public function test_visitor_citizen_payload_is_redacted_while_admin_still_sees_identity(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'name' => 'Ranya Secret Citizen',
            'national_id' => '0199887766',
            'phone' => '0991112233',
            'email' => 'secret.citizen@example.test',
        ]);

        $visitorToken = $this->dashboardLoginAs($this->visitor());
        $visitorResponse = $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/dashboard/citizens/'.$citizen->id)
            ->assertOk()
            ->assertJsonPath('data.actions.can_edit', false)
            ->assertJsonPath('data.actions.can_deactivate', false);

        $this->assertStringNotContainsString('0199887766', $visitorResponse->getContent());
        $this->assertStringNotContainsString('secret.citizen@example.test', $visitorResponse->getContent());
        $this->assertStringNotContainsString('0991112233', $visitorResponse->getContent());
        $this->assertStringNotContainsString('Ranya Secret Citizen', $visitorResponse->getContent());

        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        $adminToken = $this->dashboardLoginAs($admin);
        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/dashboard/citizens/'.$citizen->id)
            ->assertOk()
            ->assertJsonPath('data.national_id', '0199887766')
            ->assertJsonPath('data.actions.can_edit', true);
    }

    public function test_name_search_cannot_be_used_to_recover_a_citizen_identity(): void
    {
        [$citizen] = $this->privateApplication();
        $token = $this->dashboardLoginAs($this->visitor());

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/applications?search='.urlencode($citizen->name))
            ->assertOk();

        $this->assertStringNotContainsString($citizen->name, $response->getContent());
        $this->assertStringNotContainsString($citizen->national_id, $response->getContent());
    }

    public function test_visitor_mutations_are_forbidden_and_do_not_change_state(): void
    {
        [$citizen, $application] = $this->privateApplication();
        $token = $this->dashboardLoginAs($this->visitor());
        $users = User::query()->count();
        $applications = LicenseApplication::query()->count();

        $this->withHeader('Authorization', 'Bearer '.$token);

        $this->postJson('/api/dashboard/employees', [
            'name' => 'Injected Employee',
            'email' => 'injected@test.sy',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertForbidden()
            ->assertJsonPath('message', __('messages.visitor.read_only'));

        $this->putJson('/api/dashboard/citizens/'.$citizen->id, ['name' => 'Changed'])->assertForbidden();
        $this->postJson('/api/dashboard/citizens/'.$citizen->id.'/deactivate')->assertForbidden();
        $this->postJson('/api/dashboard/applications/'.$application->id.'/reject', ['reason' => 'no'])->assertForbidden();
        $this->postJson('/api/admin/applications/'.$application->id.'/issue-license')->assertForbidden();
        $this->postJson('/api/admin/fines', [
            'citizen_id' => $citizen->id,
            'amount' => 10,
            'reason' => 'demo',
        ])->assertForbidden();
        $this->postJson('/api/dashboard/payments/1/verify')->assertForbidden();
        $this->postJson('/api/dashboard/document-reviews/documents/1/approve')->assertForbidden();
        $this->postJson('/api/dashboard/licenses/1/print')->assertForbidden();
        $this->postJson('/api/dashboard/licenses/1/block', ['reason' => 'demo'])->assertForbidden();
        $this->putJson('/api/notifications/1/read')->assertForbidden();
        $this->putJson('/api/notifications/read-all')->assertForbidden();
        $this->postJson('/api/ai-agent/message', ['message' => 'issue a license'])->assertForbidden();
        $this->postJson('/api/applications/1/documents')->assertForbidden();
        $this->putJson('/api/profile/update', ['name' => 'Hijack'])->assertForbidden();
        $this->patchJson('/api/dashboard/access-control/roles/1/permissions', [
            'permission_ids' => [1],
        ])->assertForbidden();

        $this->assertSame($users, User::query()->count());
        $this->assertSame($applications, LicenseApplication::query()->count());
        $this->assertSame(ApplicationStatus::Draft, $application->fresh()->status);
        $this->assertSame('Ranya Secret Citizen', $citizen->fresh()->name);
        $this->assertDatabaseMissing('users', ['email' => 'injected@test.sy']);
    }

    public function test_english_and_arabic_read_only_messages(): void
    {
        $token = $this->dashboardLoginAs($this->visitor());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept-Language', 'en')
            ->postJson('/api/dashboard/employees', [])
            ->assertForbidden()
            ->assertJsonPath('message', 'The visitor account is read-only and cannot change system data.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept-Language', 'ar')
            ->postJson('/api/dashboard/employees', [])
            ->assertForbidden()
            ->assertJsonPath('message', 'حساب الزائر للعرض فقط، ولا يمكنه تعديل بيانات النظام.');
    }

    public function test_sensitive_reads_and_direct_permission_grants_do_not_elevate_the_visitor(): void
    {
        $visitor = $this->visitor();
        $manageEmployees = Permission::query()->where('name', 'manage_employees')->firstOrFail();
        $visitor->directPermissions()->attach($manageEmployees->id);

        $token = $this->dashboardLoginAs($visitor->fresh());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.direct_permissions', [])
            ->assertJsonMissing(['manage_employees']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employees/1')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/audit-logs')
            ->assertForbidden()
            ->assertJsonPath('message', __('messages.visitor.read_only'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/licenses/1/portrait')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/access-control/overview')
            ->assertForbidden();

        $this->assertFalse($visitor->fresh()->hasPermission('manage_employees'));
    }

    public function test_visitor_cannot_use_citizen_login_or_reset_its_password(): void
    {
        $visitor = $this->visitor();

        $this->postJson('/api/auth/login', [
            'email' => $visitor->email,
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', __('messages.visitor.dashboard_login_only'));

        $this->postJson('/api/dashboard/auth/forgot-password', [
            'email' => $visitor->email,
        ])->assertOk();

        $this->assertDatabaseMissing('otps', [
            'email' => $visitor->email,
        ]);

        $this->assertTrue(Hash::check('password', $visitor->fresh()->password));
    }

    public function test_super_admin_cannot_assign_or_recreate_the_visitor_role(): void
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create();
        $visitorRole = Role::query()->where('name', 'visitor')->firstOrFail();
        $token = $this->dashboardLoginAs($admin);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/dashboard/employees/'.$employee->id.'/roles', [
                'role_id' => $visitorRole->id,
                'reason' => 'محاولة تعيين زائر',
            ])->assertStatus(422);

        $this->assertSame('fines_employee', $employee->fresh()->role->name);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/dashboard/access-control/roles', [
                'name' => 'visitor',
                'display_name' => 'محاولة',
            ])->assertStatus(422);
    }

    public function test_provisioner_is_idempotent_and_refuses_to_overwrite_another_account(): void
    {
        config([
            'demo.visitor.email' => 'provisioned-visitor@syrtak.demo',
            'demo.visitor.phone' => '0900000099',
            'demo.visitor.password' => 'Syrtak@Visitor2026!',
        ]);

        $first = app(VisitorAccountProvisioner::class)->provision(true);
        $second = app(VisitorAccountProvisioner::class)->provision(true);

        $this->assertTrue($first['ok']);
        $this->assertSame('created', $first['status']);
        $this->assertTrue($second['ok']);
        $this->assertSame('updated', $second['status']);
        $this->assertSame(1, User::withTrashed()->where('email', 'provisioned-visitor@syrtak.demo')->count());

        $account = User::query()->where('email', 'provisioned-visitor@syrtak.demo')->firstOrFail();
        $this->assertSame('visitor', $account->role->name);
        $this->assertTrue(Hash::check('Syrtak@Visitor2026!', $account->password));
        $this->assertNotSame('Syrtak@Visitor2026!', $account->password);
        $this->assertSame([], $account->directPermissionNames());

        $audit = AuditLog::query()->where('action', 'visitor.account_created')->firstOrFail();
        $this->assertStringNotContainsString('Syrtak@Visitor2026!', json_encode($audit->toArray()));

        $employee = User::factory()->dashboardEmployee('payment_employee')->create([
            'email' => 'legitimate@test.sy',
            'password' => Hash::make('employee-secret'),
        ]);
        config(['demo.visitor.email' => 'legitimate@test.sy']);

        $collision = app(VisitorAccountProvisioner::class)->provision(true);
        $this->assertFalse($collision['ok']);
        $this->assertSame('email_collision', $collision['status']);
        $this->assertFalse($collision['written']);
        $employee->refresh();
        $this->assertSame('payment_employee', $employee->role->name);
        $this->assertTrue(Hash::check('employee-secret', $employee->password));
    }

    public function test_disabled_seeder_does_not_create_an_account(): void
    {
        config(['demo.visitor.enabled' => false]);

        $this->seed(VisitorAccountSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'visitor@syrtak.demo']);
    }

    public function test_seeder_throws_instead_of_overwriting_a_collision(): void
    {
        User::factory()->dashboardEmployee('employee')->create([
            'email' => 'visitor@syrtak.demo',
        ]);

        $this->expectException(RuntimeException::class);

        $this->seed(VisitorAccountSeeder::class);
    }

    private function visitor(): User
    {
        return User::factory()->dashboardEmployee('visitor')->create([
            'email' => 'visitor-login@test.sy',
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * @return array{0: User, 1: LicenseApplication}
     */
    private function privateApplication(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'name' => 'Ranya Secret Citizen',
            'national_id' => '0199887766',
            'phone' => '0991112233',
            'email' => 'secret.citizen@example.test',
        ]);
        $licenseType = LicenseType::query()->create([
            'name' => 'Private Catalog Label',
            'code' => 'private_demo',
            'minimum_age' => 18,
            'validity_years' => 5,
            'is_active' => true,
        ]);
        $serviceType = ServiceType::query()->create([
            'name' => 'New license demo',
            'code' => 'new_license_demo',
            'description' => 'demo',
            'is_active' => true,
        ]);
        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-2026-DEMO0001',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
        ]);

        return [$citizen, $application];
    }
}
