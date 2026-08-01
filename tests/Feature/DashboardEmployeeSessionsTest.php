<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmployeeSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardEmployeeSessionsTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function rootSuperAdmin(): User
    {
        return User::factory()->dashboardAdmin('super_admin')->create([
            'password' => Hash::make('password'),
        ]);
    }

    private function normalAdmin(): User
    {
        return User::factory()->dashboardAdmin('admin')->create([
            'password' => Hash::make('password'),
        ]);
    }

    public function test_guest_receives_401(): void
    {
        $this->getJson('/api/dashboard/employee-sessions')->assertUnauthorized();
    }

    public function test_citizen_receives_403(): void
    {
        $citizen = User::factory()->create([
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $token = $citizen->createToken('api-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions')
            ->assertForbidden();
    }

    public function test_normal_employee_receives_403(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $token = $this->dashboardLoginAs($employee);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions')
            ->assertForbidden()
            ->assertJsonPath('message', __('messages.employee_sessions.root_super_admin_required'));
    }

    public function test_manage_employees_permission_is_not_enough(): void
    {
        $employee = User::factory()->dashboardEmployee('employee')->create([
            'password' => Hash::make('password'),
        ]);
        // Ensure manage_employees if role has it; still not root SA.
        $token = $this->dashboardLoginAs($employee);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions')
            ->assertForbidden();
    }

    public function test_admin_role_receives_403(): void
    {
        $admin = $this->normalAdmin();
        $token = $this->dashboardLoginAs($admin);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions/stats')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions/options')
            ->assertForbidden();
    }

    public function test_root_super_admin_can_list_stats_options(): void
    {
        $root = $this->rootSuperAdmin();
        $token = $this->dashboardLoginAs($root);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['items', 'pagination']]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'total_sessions',
                'active_sessions',
                'idle_sessions',
                'expired_sessions',
                'logged_out_sessions',
                'revoked_sessions',
                'active_employees',
            ]]);

        $options = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions/options')
            ->assertOk()
            ->json('data');

        $this->assertSame('نشطة', collect($options['statuses'])->firstWhere('value', 'active')['label']);
        $this->assertStringNotContainsString('messages.', json_encode($options));
    }

    public function test_list_supports_pagination_and_filters(): void
    {
        $root = $this->rootSuperAdmin();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'name' => 'Filter Target Employee',
            'password' => Hash::make('password'),
        ]);
        $this->dashboardLoginAs($employee);
        $token = $this->dashboardLoginAs($root);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions?search=Filter%20Target&per_page=10&page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 10);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions?role=fines_employee')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions?status=active&device_type=desktop')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions?employee_id='.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_citizens_excluded_from_list(): void
    {
        $root = $this->rootSuperAdmin();
        $citizen = User::factory()->create(['name' => 'Citizen Session Leak']);
        EmployeeSession::query()->create([
            'session_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $citizen->id,
            'auth_driver' => 'sanctum',
            'logged_in_at' => now(),
            'last_seen_at' => now(),
            'device_type' => 'desktop',
        ]);

        $token = $this->dashboardLoginAs($root);
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions?search=Citizen%20Session')
            ->assertOk();

        $this->assertSame(0, $response->json('data.pagination.total'));
    }

    public function test_details_hide_token_secrets_and_me_exposes_flags(): void
    {
        $root = $this->rootSuperAdmin();
        $token = $this->dashboardLoginAs($root);
        $session = EmployeeSession::query()->where('user_id', $root->id)->firstOrFail();

        $details = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions/'.$session->session_uuid)
            ->assertOk()
            ->json('data');

        $encoded = json_encode($details);
        $this->assertStringNotContainsString('personal_access_token', $encoded);
        $this->assertArrayNotHasKey('personal_access_token_id', $details);
        $this->assertArrayHasKey('user_agent', $details);
        $this->assertTrue($details['is_current_session']);

        $me = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk()
            ->json('data.user');

        $this->assertTrue($me['is_root_super_admin']);
        $this->assertTrue($me['can_manage_employee_sessions']);
    }

    public function test_employee_history_endpoint(): void
    {
        $root = $this->rootSuperAdmin();
        $employee = User::factory()->dashboardEmployee('audit_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $this->dashboardLoginAs($employee);
        $token = $this->dashboardLoginAs($root);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employees/'.$employee->id.'/sessions')
            ->assertOk()
            ->assertJsonPath('data.employee.id', $employee->id)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_idor_unknown_session_returns_404(): void
    {
        $root = $this->rootSuperAdmin();
        $token = $this->dashboardLoginAs($root);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions/00000000-0000-4000-8000-000000000099')
            ->assertNotFound();
    }
}
