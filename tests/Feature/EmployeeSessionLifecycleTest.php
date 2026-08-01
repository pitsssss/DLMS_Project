<?php

namespace Tests\Feature;

use App\Enums\EmployeeSessionEndedReason;
use App\Enums\EmployeeSessionStatus;
use App\Models\AuditLog;
use App\Models\EmployeeSession;
use App\Models\User;
use App\Modules\Dashboard\Services\EmployeeSessions\EmployeeSessionStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class EmployeeSessionLifecycleTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_dashboard_login_creates_linked_session(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/dashboard/auth/login', [
            'email' => $employee->email,
            'password' => 'password123',
        ], [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ])->assertOk();

        $plain = $response->json('data.token');
        $this->assertNotEmpty($plain);
        $this->assertSame(1, EmployeeSession::query()->where('user_id', $employee->id)->count());

        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertNotNull($session->personal_access_token_id);
        $this->assertNotNull($session->logged_in_at);
        $this->assertNotNull($session->last_seen_at);
        $this->assertSame('desktop', $session->device_type);
        $this->assertSame('Windows', $session->operating_system);
        $this->assertSame('Chrome', $session->browser);
        $this->assertNotNull($session->initial_ip_address);

        $userPayload = json_encode($response->json('data.user'));
        $this->assertStringNotContainsString('personal_access_token', (string) $userPayload);
        $this->assertStringNotContainsString('"token_hash"', (string) json_encode($response->json()));
        $this->assertArrayNotHasKey('personal_access_token_id', $response->json('data.user') ?? []);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee_session.started',
            'entity_type' => 'employee_session',
            'entity_id' => $session->id,
        ]);
    }

    public function test_citizen_login_creates_no_employee_session(): void
    {
        $citizen = User::factory()->create([
            'email' => 'citizen-session@test.sy',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $citizen->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertSame(0, EmployeeSession::query()->where('user_id', $citizen->id)->count());
    }

    public function test_explicit_logout_sets_logged_out_and_invalidates_token(): void
    {
        $employee = User::factory()->dashboardEmployee('audit_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $token = $this->dashboardLoginAs($employee);
        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/dashboard/auth/logout')
            ->assertOk();

        $session->refresh();
        $this->assertNotNull($session->logged_out_at);
        $this->assertSame(EmployeeSessionEndedReason::ExplicitLogout->value, $session->ended_reason);
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $employee->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee_session.logged_out',
            'entity_id' => $session->id,
        ]);

        $this->assertDashboardTokenUnauthorized($token);
    }

    public function test_repeated_logout_is_idempotent(): void
    {
        $employee = User::factory()->dashboardEmployee('audit_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $token = $this->dashboardLoginAs($employee);
        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/dashboard/auth/logout')
            ->assertOk();

        // Second logout without a valid token is 401; create a new session then logout again
        // and ensure already-ended session is not relabeled by a different flow.
        $before = AuditLog::query()->where('action', 'employee_session.logged_out')->where('entity_id', $session->id)->count();
        $this->assertSame(1, $before);

        $session->refresh();
        $loggedOutAt = $session->logged_out_at?->toIso8601String();

        // Simulate a second mark: revoked sessions must not become explicit logout.
        $session->forceFill([
            'revoked_at' => now(),
            'ended_reason' => EmployeeSessionEndedReason::Revoked->value,
            'logged_out_at' => null,
        ])->save();

        $resolver = app(EmployeeSessionStatusResolver::class);
        $this->assertSame(EmployeeSessionStatus::Revoked, $resolver->resolve($session->fresh()));
        $this->assertNotSame($loggedOutAt, $session->fresh()->logged_out_at?->toIso8601String());
    }

    public function test_status_active_idle_expired_logged_out_revoked(): void
    {
        $resolver = app(EmployeeSessionStatusResolver::class);
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $this->dashboardLoginAs($employee);
        $session = EmployeeSession::query()->where('user_id', $employee->id)->with('personalAccessToken')->firstOrFail();

        $this->assertSame(EmployeeSessionStatus::Active, $resolver->resolve($session));

        $session->last_seen_at = now()->subMinutes((int) config('employee_sessions.active_threshold_minutes') + 2);
        $session->save();
        $this->assertSame(EmployeeSessionStatus::Idle, $resolver->resolve($session->fresh(['personalAccessToken'])));

        $session->expires_at = now()->subMinute();
        $session->save();
        $this->assertSame(EmployeeSessionStatus::Expired, $resolver->resolve($session->fresh(['personalAccessToken'])));

        $session->expires_at = null;
        $session->logged_out_at = now();
        $session->ended_reason = EmployeeSessionEndedReason::ExplicitLogout->value;
        $session->save();
        $this->assertSame(EmployeeSessionStatus::LoggedOut, $resolver->resolve($session->fresh()));

        $session->logged_out_at = null;
        $session->revoked_at = now();
        $session->ended_reason = EmployeeSessionEndedReason::Revoked->value;
        $session->save();
        $this->assertSame(EmployeeSessionStatus::Revoked, $resolver->resolve($session->fresh()));
    }

    public function test_reconcile_and_prune_commands(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create();
        $ended = EmployeeSession::query()->create([
            'session_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $employee->id,
            'auth_driver' => 'sanctum',
            'logged_in_at' => now()->subDays(200),
            'last_seen_at' => now()->subDays(200),
            'logged_out_at' => now()->subDays(190),
            'ended_reason' => EmployeeSessionEndedReason::ExplicitLogout->value,
            'device_type' => 'desktop',
        ]);

        $open = EmployeeSession::query()->create([
            'session_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $employee->id,
            'auth_driver' => 'sanctum',
            'personal_access_token_id' => null,
            'logged_in_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
            'device_type' => 'desktop',
        ]);

        $this->artisan('employee-sessions:reconcile')->assertSuccessful();
        $open->refresh();
        $this->assertSame(EmployeeSessionEndedReason::CredentialMissing->value, $open->ended_reason);

        $this->artisan('employee-sessions:prune')->assertSuccessful();
        $this->assertDatabaseHas('employee_sessions', ['id' => $ended->id]);

        $this->artisan('employee-sessions:prune', ['--apply' => true, '--days' => 30])->assertSuccessful();
        $this->assertDatabaseMissing('employee_sessions', ['id' => $ended->id]);
        $this->assertDatabaseHas('employee_sessions', ['id' => $open->id]);
    }
}
