<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\PushDevice;
use App\Models\User;
use App\Modules\Devices\Services\PushDeviceService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushDeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_authenticated_citizen_can_register_a_device(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $response = $this->postJson('/api/devices/push-token', [
            'device_id' => 'install-uuid-001',
            'platform' => 'android',
            'token' => 'fcm-token-alpha-001',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.device_id', 'install-uuid-001')
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.registered', true);

        $this->assertSame(1, PushDevice::query()->count());
        $device = PushDevice::query()->first();
        $this->assertSame($citizen->id, $device->user_id);
        $this->assertSame('android', $device->platform);
        $this->assertNotNull($device->last_registered_at);
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('token_hash', $response->json('data'));
        $this->assertStringNotContainsString('fcm-token-alpha-001', $response->getContent());
    }

    public function test_token_is_stored_encrypted_and_hash_is_deterministic(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $token = 'fcm-token-secret-value';
        $this->postJson('/api/devices/push-token', [
            'device_id' => 'install-uuid-002',
            'platform' => 'ios',
            'token' => $token,
        ])->assertOk();

        $raw = DB::table('push_devices')->first();
        $this->assertNotSame($token, $raw->token);
        $this->assertSame(hash('sha256', $token), $raw->token_hash);
        $this->assertSame($token, Crypt::decryptString($raw->token));

        $model = PushDevice::query()->first();
        $this->assertSame($token, $model->token);
        $serialized = $model->toArray();
        $this->assertArrayNotHasKey('token', $serialized);
        $this->assertArrayNotHasKey('token_hash', $serialized);
    }

    public function test_repeated_register_is_idempotent_and_refreshes_last_registered_at(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $payload = [
            'device_id' => 'install-same',
            'platform' => 'android',
            'token' => 'same-token',
        ];

        $this->postJson('/api/devices/push-token', $payload)->assertOk();
        $first = PushDevice::query()->first();
        $firstAt = $first->last_registered_at->copy();

        $this->travel(5)->seconds();

        $this->postJson('/api/devices/push-token', $payload)->assertOk();

        $this->assertSame(1, PushDevice::query()->count());
        $second = PushDevice::query()->first();
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->last_registered_at->greaterThan($firstAt));
    }

    public function test_citizen_can_register_multiple_devices(): void
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

        $this->assertSame(2, PushDevice::query()->where('user_id', $citizen->id)->count());
        $this->assertTrue(PushDevice::query()->where('device_id', 'phone-a')->where('platform', 'android')->exists());
        $this->assertTrue(PushDevice::query()->where('device_id', 'phone-b')->where('platform', 'ios')->exists());
    }

    public function test_owner_can_unregister_one_device_idempotently(): void
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

        $this->deleteJson('/api/devices/push-token', ['device_id' => 'phone-a'])
            ->assertOk()
            ->assertJsonPath('data.device_id', 'phone-a')
            ->assertJsonPath('data.unregistered', true);

        $this->assertSame(0, PushDevice::query()->where('device_id', 'phone-a')->count());
        $this->assertSame(1, PushDevice::query()->where('device_id', 'phone-b')->count());

        $this->deleteJson('/api/devices/push-token', ['device_id' => 'phone-a'])
            ->assertOk()
            ->assertJsonPath('data.unregistered', true);

        $this->assertSame(1, PushDevice::query()->count());
    }

    public function test_arabic_and_english_envelope_messages(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/devices/push-token', [
                'device_id' => 'loc-device',
                'platform' => 'android',
                'token' => 'loc-token',
            ])
            ->assertOk();

        $this->assertSame(Lang::get('messages.devices.registered', [], 'ar'), $ar->json('message'));
        $this->assertStringNotContainsString('messages.', (string) $ar->json('message'));
        $this->assertSame('android', $ar->json('data.platform'));
        $this->assertTrue($ar->json('data.registered'));

        $en = $this->withHeader('Accept-Language', 'en')
            ->deleteJson('/api/devices/push-token', ['device_id' => 'loc-device'])
            ->assertOk();

        $this->assertSame(Lang::get('messages.devices.unregistered', [], 'en'), $en->json('message'));
        $this->assertStringNotContainsString('messages.', (string) $en->json('message'));
        $this->assertSame('loc-device', $en->json('data.device_id'));
        $this->assertTrue($en->json('data.unregistered'));
    }

    public function test_validation_rejects_missing_and_invalid_fields(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->postJson('/api/devices/push-token', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_id', 'platform', 'token']);

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'ok',
            'platform' => 'windows',
            'token' => 't',
        ])->assertStatus(422)->assertJsonValidationErrors(['platform']);

        $this->postJson('/api/devices/push-token', [
            'device_id' => str_repeat('x', 129),
            'platform' => 'android',
            'token' => 't',
        ])->assertStatus(422)->assertJsonValidationErrors(['device_id']);

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'ok',
            'platform' => 'android',
            'token' => str_repeat('t', 4097),
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);

        $this->deleteJson('/api/devices/push-token', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_id']);
    }

    public function test_same_token_cannot_exist_in_two_rows(): void
    {
        $citizen = $this->citizen();
        $service = app(PushDeviceService::class);

        $service->register($citizen, [
            'device_id' => 'd1',
            'platform' => 'android',
            'token' => 'shared-token',
        ]);

        $service->register($citizen, [
            'device_id' => 'd2',
            'platform' => 'ios',
            'token' => 'shared-token',
        ]);

        $this->assertSame(1, PushDevice::query()->count());
        $row = PushDevice::query()->first();
        $this->assertSame('d2', $row->device_id);
        $this->assertSame('ios', $row->platform);
        $this->assertSame(hash('sha256', 'shared-token'), $row->token_hash);
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
    }
}
