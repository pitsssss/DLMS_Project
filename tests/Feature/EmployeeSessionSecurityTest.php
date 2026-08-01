<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmployeeSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class EmployeeSessionSecurityTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_normal_admin_cannot_access_any_management_route(): void
    {
        $admin = User::factory()->dashboardAdmin('admin')->create([
            'password' => Hash::make('password'),
        ]);
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $this->dashboardLoginAs($employee);
        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();
        $token = $this->dashboardLoginAs($admin);

        $routes = [
            ['GET', '/api/dashboard/employee-sessions'],
            ['GET', '/api/dashboard/employee-sessions/stats'],
            ['GET', '/api/dashboard/employee-sessions/options'],
            ['GET', '/api/dashboard/employee-sessions/'.$session->session_uuid],
            ['GET', '/api/dashboard/employee-sessions/'.$session->session_uuid.'/audit-logs'],
            ['GET', '/api/dashboard/employees/'.$employee->id.'/sessions'],
        ];

        foreach ($routes as [$method, $uri]) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->json($method, $uri)
                ->assertForbidden();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'reason' => 'محاولة غير مصرح بها',
                'password_confirmation' => 'password',
            ])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/dashboard/employees/'.$employee->id.'/sessions/revoke-all', [
                'reason' => 'محاولة غير مصرح بها',
                'password_confirmation' => 'password',
            ])
            ->assertForbidden();
    }

    public function test_no_token_or_password_secrets_in_json_or_audit(): void
    {
        $root = User::factory()->dashboardAdmin('super_admin')->create([
            'password' => Hash::make('password'),
        ]);
        $employee = User::factory()->dashboardEmployee('audit_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $this->dashboardLoginAs($employee);
        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();
        $token = $this->dashboardLoginAs($root);

        $list = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions')
            ->assertOk()
            ->json();

        $details = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions/'.$session->session_uuid)
            ->assertOk()
            ->json();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'reason' => 'فحص السرية',
                'password_confirmation' => 'password',
            ])
            ->assertOk();

        $blob = json_encode([$list, $details]);
        $this->assertStringNotContainsString('password_confirmation', (string) $blob);
        $this->assertStringNotContainsString('personal_access_token_id', (string) $blob);
        $this->assertStringNotContainsString('"token":', strtolower((string) json_encode($details['data'])));

        $audit = AuditLog::query()->where('action', 'employee_session.revoked')->latest('id')->first();
        $this->assertNotNull($audit);
        $encodedAudit = json_encode([$audit->old_values, $audit->new_values, $audit->user_agent]);
        $this->assertStringNotContainsString('password_confirmation', (string) $encodedAudit);
        $this->assertStringNotContainsString('Bearer ', (string) $encodedAudit);
        $this->assertStringNotContainsString('password', strtolower((string) json_encode($audit->new_values)));
    }

    public function test_raw_user_agent_only_in_details(): void
    {
        $root = User::factory()->dashboardAdmin('super_admin')->create([
            'password' => Hash::make('password'),
        ]);
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $employee->email,
            'password' => 'password',
        ], [
            'User-Agent' => 'Mozilla/5.0 UniqueAgentString/1.0 Chrome/120.0.0.0',
        ])->assertOk();

        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();
        $token = $this->dashboardLoginAs($root);

        $listItem = collect($this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions?employee_id='.$employee->id)
            ->json('data.items'))->first();

        $this->assertArrayNotHasKey('user_agent', $listItem);

        $details = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/employee-sessions/'.$session->session_uuid)
            ->json('data');

        $this->assertStringContainsString('UniqueAgentString', (string) $details['user_agent']);
    }

    public function test_me_flags_false_for_admin_role(): void
    {
        $admin = User::factory()->dashboardAdmin('admin')->create([
            'password' => Hash::make('password'),
        ]);
        $token = $this->dashboardLoginAs($admin);

        $me = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk()
            ->json('data.user');

        $this->assertTrue($me['is_super_admin']);
        $this->assertFalse($me['is_root_super_admin']);
        $this->assertFalse($me['can_manage_employee_sessions']);
    }
}
