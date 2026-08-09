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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushDeviceTokenRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_same_device_new_token_updates_same_row(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'phone-a',
            'platform' => 'android',
            'token' => 'old-token',
        ])->assertOk();

        $originalId = PushDevice::query()->first()->id;
        $oldHash = hash('sha256', 'old-token');

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'phone-a',
            'platform' => 'android',
            'token' => 'new-token',
        ])->assertOk();

        $this->assertSame(1, PushDevice::query()->count());
        $device = PushDevice::query()->first();
        $this->assertSame($originalId, $device->id);
        $this->assertSame('new-token', $device->token);
        $this->assertSame(hash('sha256', 'new-token'), $device->token_hash);
        $this->assertNotSame($oldHash, $device->token_hash);
        $this->assertSame(0, PushDevice::query()->where('token_hash', $oldHash)->count());
    }

    public function test_rotating_phone_a_does_not_alter_phone_b(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'phone-a',
            'platform' => 'android',
            'token' => 'token-a-old',
        ])->assertOk();
        $this->postJson('/api/devices/push-token', [
            'device_id' => 'phone-b',
            'platform' => 'ios',
            'token' => 'token-b',
        ])->assertOk();

        $phoneBBefore = PushDevice::query()->where('device_id', 'phone-b')->first();

        $this->postJson('/api/devices/push-token', [
            'device_id' => 'phone-a',
            'platform' => 'android',
            'token' => 'token-a-new',
        ])->assertOk();

        $phoneBAfter = PushDevice::query()->where('device_id', 'phone-b')->first();
        $this->assertSame($phoneBBefore->id, $phoneBAfter->id);
        $this->assertSame('token-b', $phoneBAfter->token);
        $this->assertSame($phoneBBefore->token_hash, $phoneBAfter->token_hash);
        $this->assertSame(2, PushDevice::query()->count());
    }

    public function test_duplicate_token_on_two_devices_reconciles_to_one_row(): void
    {
        $citizen = $this->citizen();
        $service = app(PushDeviceService::class);

        $service->register($citizen, [
            'device_id' => 'device-old',
            'platform' => 'android',
            'token' => 'dup-token',
        ]);
        $service->register($citizen, [
            'device_id' => 'device-new',
            'platform' => 'android',
            'token' => 'other-token',
        ]);

        $this->assertSame(2, PushDevice::query()->count());

        // Submit token that already belongs to device-old while registering device-new.
        $service->register($citizen, [
            'device_id' => 'device-new',
            'platform' => 'ios',
            'token' => 'dup-token',
        ]);

        $this->assertSame(1, PushDevice::query()->count());
        $row = PushDevice::query()->first();
        $this->assertSame('device-new', $row->device_id);
        $this->assertSame('ios', $row->platform);
        $this->assertSame(hash('sha256', 'dup-token'), $row->token_hash);
    }

    public function test_token_uniqueness_constraint_is_enforced_at_database_level(): void
    {
        $citizen = $this->citizen();
        $hash = hash('sha256', 'unique-token');

        PushDevice::query()->create([
            'user_id' => $citizen->id,
            'device_id' => 'd1',
            'platform' => 'android',
            'token' => 'unique-token',
            'token_hash' => $hash,
            'last_registered_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PushDevice::query()->create([
            'user_id' => $citizen->id,
            'device_id' => 'd2',
            'platform' => 'ios',
            'token' => 'unique-token-different-ciphertext-path',
            'token_hash' => $hash,
            'last_registered_at' => now(),
        ]);
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
    }
}
