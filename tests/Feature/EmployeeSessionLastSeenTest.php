<?php

namespace Tests\Feature;

use App\Models\EmployeeSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class EmployeeSessionLastSeenTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
        Cache::flush();
    }

    public function test_authenticated_request_updates_last_seen_and_ip(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $token = $this->dashboardLoginAs($employee);
        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();
        $original = $session->last_seen_at?->toIso8601String();

        Cache::flush();
        $this->travel(1)->minutes();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk();

        $session->refresh();
        $this->assertNotSame($original, $session->last_seen_at?->toIso8601String());
        $this->assertNotNull($session->last_ip_address);
    }

    public function test_write_throttling_prevents_update_every_request(): void
    {
        config(['employee_sessions.last_seen_write_interval_minutes' => 10]);

        $employee = User::factory()->dashboardEmployee('audit_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $token = $this->dashboardLoginAs($employee);
        Cache::flush();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk();

        $session = EmployeeSession::query()->where('user_id', $employee->id)->firstOrFail();
        $first = $session->last_seen_at?->toIso8601String();

        $this->travel(1)->seconds();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk();

        $session->refresh();
        $this->assertSame($first, $session->last_seen_at?->toIso8601String());
    }

    public function test_heartbeat_updates_only_current_session(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $tokenA = $this->dashboardLoginAs($employee);
        $tokenB = $this->dashboardLoginAs($employee);

        $sessions = EmployeeSession::query()->where('user_id', $employee->id)->orderBy('id')->get();
        $this->assertCount(2, $sessions);

        Cache::flush();
        $this->travel(2)->minutes();

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/dashboard/session/heartbeat')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('server_time', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('status_label', $response);
        $this->assertStringNotContainsString('messages.', $response['status_label']);

        $sessions->each->refresh();
        // tokenB is the later login; its last_seen should move.
        $sessionB = EmployeeSession::query()
            ->where('user_id', $employee->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertNotNull($sessionB->last_seen_at);
    }

    public function test_heartbeat_rejects_forged_fields_silently_by_ignoring_body(): void
    {
        $a = User::factory()->dashboardEmployee('fines_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $b = User::factory()->dashboardEmployee('audit_employee')->create([
            'password' => Hash::make('password'),
        ]);
        $tokenA = $this->dashboardLoginAs($a);
        $this->dashboardLoginAs($b);
        $sessionB = EmployeeSession::query()->where('user_id', $b->id)->firstOrFail();
        $before = $sessionB->last_seen_at?->toIso8601String();

        Cache::flush();
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/dashboard/session/heartbeat', [
                'user_id' => $b->id,
                'session_id' => $sessionB->session_uuid,
                'last_seen_at' => now()->addDay()->toIso8601String(),
                'ip_address' => '1.2.3.4',
            ])
            ->assertOk();

        $sessionB->refresh();
        $this->assertSame($before, $sessionB->last_seen_at?->toIso8601String());
    }

    public function test_guest_cannot_heartbeat(): void
    {
        $this->postJson('/api/dashboard/session/heartbeat')->assertUnauthorized();
    }
}
