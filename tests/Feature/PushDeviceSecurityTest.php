<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\PushDevice;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushDeviceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/devices/push-token', [
            'device_id' => 'd1',
            'platform' => 'android',
            'token' => 't1',
        ])->assertUnauthorized();

        $this->deleteJson('/api/devices/push-token', [
            'device_id' => 'd1',
        ])->assertUnauthorized();
    }

    public function test_employee_is_rejected_by_citizen_middleware(): void
    {
        $employee = User::factory()->dashboardEmployee()->create();
        Sanctum::actingAs($employee);

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'd1',
            'platform' => 'android',
            'token' => 't1',
        ])->assertForbidden();

        $this->deleteJson('/api/devices/push-token', [
            'device_id' => 'd1',
        ])->assertForbidden();

        $this->assertSame(0, PushDevice::query()->count());
    }

    public function test_request_user_id_cannot_register_for_another_user(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();
        Sanctum::actingAs($owner);

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'mine',
            'platform' => 'android',
            'token' => 'token-mine',
            'user_id' => $other->id,
        ])->assertOk();

        $device = PushDevice::query()->first();
        $this->assertSame($owner->id, $device->user_id);
        $this->assertSame(0, PushDevice::query()->where('user_id', $other->id)->count());
    }

    public function test_citizen_cannot_unregister_another_citizens_device(): void
    {
        $owner = $this->citizen();
        $intruder = $this->citizen();

        Sanctum::actingAs($owner);
        $this->postJson('/api/devices/push-token', [
            'device_id' => 'shared-looking-id',
            'platform' => 'android',
            'token' => 'owner-token',
        ])->assertOk();

        Sanctum::actingAs($intruder);
        $this->deleteJson('/api/devices/push-token', [
            'device_id' => 'shared-looking-id',
        ])->assertOk()
            ->assertJsonPath('data.unregistered', true);

        $this->assertSame(1, PushDevice::query()->where('user_id', $owner->id)->count());
        $this->assertSame($owner->id, PushDevice::query()->first()->user_id);
    }

    public function test_cross_user_token_reassignment_is_atomic_and_private(): void
    {
        $previous = $this->citizen();
        $current = $this->citizen();

        Sanctum::actingAs($previous);
        $this->postJson('/api/devices/push-token', [
            'device_id' => 'install-1',
            'platform' => 'android',
            'token' => 'reassigned-token',
        ])->assertOk();

        Sanctum::actingAs($current);
        $response = $this->postJson('/api/devices/push-token', [
            'device_id' => 'install-1',
            'platform' => 'android',
            'token' => 'reassigned-token',
        ])->assertOk();

        $this->assertSame(1, PushDevice::query()->count());
        $device = PushDevice::query()->first();
        $this->assertSame($current->id, $device->user_id);
        $this->assertSame(0, PushDevice::query()->where('user_id', $previous->id)->count());

        $content = $response->getContent();
        $this->assertStringNotContainsString('reassigned-token', $content);
        $this->assertStringNotContainsString((string) $previous->id, (string) json_encode($response->json('data')));
        $this->assertArrayNotHasKey('user_id', $response->json('data'));
        $this->assertArrayNotHasKey('previous_user_id', $response->json('data') ?? []);
    }

    public function test_no_device_list_endpoint_exists(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->getJson('/api/devices')->assertNotFound();
        $this->getJson('/api/devices/push-token')->assertStatus(405);
    }

    public function test_token_is_not_logged_during_normal_registration(): void
    {
        Log::spy();

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $token = 'super-secret-fcm-token-should-not-log';
        $this->postJson('/api/devices/push-token', [
            'device_id' => 'log-check',
            'platform' => 'ios',
            'token' => $token,
        ])->assertOk();

        Log::shouldNotHaveReceived('info', function (...$args) use ($token) {
            return str_contains(json_encode($args), $token);
        });
        Log::shouldNotHaveReceived('debug', function (...$args) use ($token) {
            return str_contains(json_encode($args), $token);
        });
        Log::shouldNotHaveReceived('warning', function (...$args) use ($token) {
            return str_contains(json_encode($args), $token);
        });
        Log::shouldNotHaveReceived('error', function (...$args) use ($token) {
            return str_contains(json_encode($args), $token);
        });
    }

    public function test_logout_of_one_device_does_not_unregister_other_devices(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'phone-a',
            'platform' => 'android',
            'token' => 'token-a',
        ])->assertOk();
        $this->postJson('/api/devices/push-token', [
            'device_id' => 'phone-b',
            'platform' => 'ios',
            'token' => 'token-b',
        ])->assertOk();

        $this->deleteJson('/api/devices/push-token', ['device_id' => 'phone-a'])->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(1, PushDevice::query()->count());
        $this->assertSame('phone-b', PushDevice::query()->first()->device_id);
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
    }
}
