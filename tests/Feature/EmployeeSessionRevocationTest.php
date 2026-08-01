<?php

namespace Tests\Feature;

use App\Enums\EmployeeSessionEndedReason;
use App\Models\AuditLog;
use App\Models\EmployeeSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class EmployeeSessionRevocationTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function root(): User
    {
        return User::factory()->dashboardAdmin('super_admin')->create([
            'password' => Hash::make('password'),
        ]);
    }

    public function test_revoke_invalidates_token_and_audits(): void
    {
        $root = $this->root();
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $employeeToken = $this->dashboardLoginAs($employee);
        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();
        $rootToken = $this->dashboardLoginAs($root);

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'reason' => 'جلسة مشبوهة',
                'password_confirmation' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $session->refresh();
        $this->assertNotNull($session->revoked_at);
        $this->assertSame($root->id, $session->revoked_by);
        $this->assertSame(EmployeeSessionEndedReason::Revoked->value, $session->ended_reason);
        $this->assertNull($session->personal_access_token_id);
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $employee->id)->count(), 'employee tokens should be deleted');

        $this->assertDashboardTokenUnauthorized($employeeToken);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee_session.revoked',
            'entity_id' => $session->id,
            'user_id' => $root->id,
        ]);
    }

    public function test_reason_required_and_duplicate_revoke_is_409(): void
    {
        $root = $this->root();
        $employee = User::factory()->dashboardEmployee('audit_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $this->dashboardLoginAs($employee);
        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();
        $rootToken = $this->dashboardLoginAs($root);

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'password_confirmation' => 'password',
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'reason' => 'إنهاء أول',
                'password_confirmation' => 'password',
            ])
            ->assertOk();

        $auditsBefore = AuditLog::query()->where('action', 'employee_session.revoked')->where('entity_id', $session->id)->count();

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'reason' => 'إنهاء مكرر',
                'password_confirmation' => 'password',
            ])
            ->assertStatus(409);

        $this->assertSame(
            $auditsBefore,
            AuditLog::query()->where('action', 'employee_session.revoked')->where('entity_id', $session->id)->count()
        );
    }

    public function test_current_session_requires_confirmation_and_password(): void
    {
        $root = $this->root();
        $rootToken = $this->dashboardLoginAs($root);
        $session = EmployeeSession::query()->where('user_id', $root->id)->firstOrFail();

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'reason' => 'إنهاء الجلسة الحالية',
                'password_confirmation' => 'password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.employee_sessions.current_session_confirmation_required'));

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'reason' => 'إنهاء الجلسة الحالية',
                'password_confirmation' => 'wrong-password',
                'confirm_current_session' => true,
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employee-sessions/'.$session->session_uuid.'/revoke', [
                'reason' => 'إنهاء الجلسة الحالية',
                'password_confirmation' => 'password',
                'confirm_current_session' => true,
            ])
            ->assertOk();

        $this->assertDashboardTokenUnauthorized($rootToken);
    }

    public function test_revoke_all_preserves_current_by_default(): void
    {
        $root = $this->root();
        $rootTokenA = $this->dashboardLoginAs($root);
        $rootTokenB = $this->dashboardLoginAs($root);
        $this->assertSame(2, EmployeeSession::query()->where('user_id', $root->id)->count());

        $response = $this->withHeader('Authorization', 'Bearer '.$rootTokenB)
            ->postJson('/api/dashboard/employees/'.$root->id.'/sessions/revoke-all', [
                'reason' => 'تنظيف الجلسات',
                'password_confirmation' => 'password',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $response['revoked_session_count']);
        $this->assertSame(1, $response['preserved_current_session_count']);

        $this->flushDashboardAuthGuards();
        $this->withHeader('Authorization', 'Bearer '.$rootTokenB)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk();

        $this->assertDashboardTokenUnauthorized($rootTokenA);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee_sessions.revoked_all',
            'entity_id' => $root->id,
        ]);
    }

    public function test_revoke_all_can_include_current_and_rejects_citizens(): void
    {
        $root = $this->root();
        $rootToken = $this->dashboardLoginAs($root);

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employees/'.$root->id.'/sessions/revoke-all', [
                'reason' => 'إنهاء الكل بما فيها الحالية',
                'password_confirmation' => 'password',
                'include_current_actor_session' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.preserved_current_session_count', 0);

        $this->assertDashboardTokenUnauthorized($rootToken);

        $root2 = $this->root();
        $root2Token = $this->dashboardLoginAs($root2);
        $citizen = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$root2Token)
            ->postJson('/api/dashboard/employees/'.$citizen->id.'/sessions/revoke-all', [
                'reason' => 'محاولة على مواطن',
                'password_confirmation' => 'password',
            ])
            ->assertNotFound();
    }

    public function test_employee_account_remains_active_after_revoke_all(): void
    {
        $root = $this->root();
        $employee = User::factory()->dashboardEmployee('license_employee')->create([
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->dashboardLoginAs($employee);
        $rootToken = $this->dashboardLoginAs($root);

        $this->withHeader('Authorization', 'Bearer '.$rootToken)
            ->postJson('/api/dashboard/employees/'.$employee->id.'/sessions/revoke-all', [
                'reason' => 'إنهاء جلسات فقط',
            ])
            ->assertOk();

        $this->assertTrue($employee->fresh()->is_active);
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $employee->id)->count());
    }
}
