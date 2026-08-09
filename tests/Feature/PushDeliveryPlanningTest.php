<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\PushDeliveryStatus;
use App\Enums\UserType;
use App\Jobs\SendPushNotificationJob;
use App\Models\PushDelivery;
use App\Models\PushDevice;
use App\Models\User;
use App\Modules\Devices\Services\PushDeviceService;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Support\NotificationEventKey;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushDeliveryPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['firebase.push.enabled' => true]);
    }

    public function test_new_notification_with_no_devices_creates_no_deliveries(): void
    {
        Queue::fake();
        $citizen = $this->citizen();

        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::FineCreated,
            ['fine_id' => 1],
            [],
            NotificationEventKey::make(NotificationType::FineCreated, 'fine:1')
        );

        $this->assertSame(0, PushDelivery::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_new_notification_with_one_device_creates_one_delivery_and_job(): void
    {
        Queue::fake();
        $citizen = $this->citizen();
        $this->registerDevice($citizen, 'd1', 'token-1');

        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::FineCreated,
            ['fine_id' => 2],
            [],
            NotificationEventKey::make(NotificationType::FineCreated, 'fine:2')
        );

        $this->assertSame(1, PushDelivery::query()->count());
        $delivery = PushDelivery::query()->first();
        $this->assertSame(PushDeliveryStatus::Pending, $delivery->status);
        Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job) use ($delivery) {
            return $job->pushDeliveryId === $delivery->id
                && $job->queue === 'push';
        });
    }

    public function test_new_notification_with_two_devices_creates_two_deliveries(): void
    {
        Queue::fake();
        $citizen = $this->citizen();
        $this->registerDevice($citizen, 'phone-a', 'token-a');
        $this->registerDevice($citizen, 'phone-b', 'token-b');

        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::FineCreated,
            ['fine_id' => 3],
            [],
            NotificationEventKey::make(NotificationType::FineCreated, 'fine:3')
        );

        $this->assertSame(2, PushDelivery::query()->count());
        Queue::assertPushed(SendPushNotificationJob::class, 2);
    }

    public function test_duplicate_business_event_does_not_create_new_push(): void
    {
        Queue::fake();
        $citizen = $this->citizen();
        $this->registerDevice($citizen, 'd1', 'token-1');
        $eventKey = NotificationEventKey::make(NotificationType::FineCreated, 'fine:dup');

        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::FineCreated,
            ['fine_id' => 4],
            [],
            $eventKey
        );
        $this->assertSame(1, PushDelivery::query()->count());
        Queue::assertPushed(SendPushNotificationJob::class, 1);

        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::FineCreated,
            ['fine_id' => 4],
            [],
            $eventKey
        );

        $this->assertSame(1, PushDelivery::query()->count());
        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_push_disabled_skips_planning_but_keeps_notification(): void
    {
        Queue::fake();
        config(['firebase.push.enabled' => false]);
        $citizen = $this->citizen();
        $this->registerDevice($citizen, 'd1', 'token-1');

        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::FineCreated,
            ['fine_id' => 5],
            [],
            NotificationEventKey::make(NotificationType::FineCreated, 'fine:5')
        );

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, PushDelivery::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_delivery_key_uniqueness_enforced(): void
    {
        config(['firebase.push.enabled' => false]);
        $citizen = $this->citizen();
        $device = $this->registerDevice($citizen, 'd1', 'token-1');
        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            'T',
            'B',
            NotificationType::FineCreated->value,
            ['fine_id' => 9],
            NotificationEventKey::make(NotificationType::FineCreated, 'fine:9')
        );

        PushDelivery::query()->create([
            'notification_id' => $notification->id,
            'push_device_id' => $device->id,
            'delivery_key' => PushDelivery::deliveryKey($notification->id, $device->id),
            'status' => PushDeliveryStatus::Pending,
            'attempts' => 0,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        PushDelivery::query()->create([
            'notification_id' => $notification->id,
            'push_device_id' => $device->id,
            'delivery_key' => PushDelivery::deliveryKey($notification->id, $device->id),
            'status' => PushDeliveryStatus::Pending,
            'attempts' => 0,
        ]);
    }

    public function test_pending_recovery_is_idempotent_for_already_sent(): void
    {
        Queue::fake();
        $citizen = $this->citizen();
        $this->registerDevice($citizen, 'd1', 'token-1');
        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            'T',
            'B',
            NotificationType::FineCreated->value,
            ['fine_id' => 10],
            null
        );

        $delivery = PushDelivery::query()->where('notification_id', $notification->id)->firstOrFail();
        $delivery->update([
            'status' => PushDeliveryStatus::Sent,
            'attempts' => 1,
            'sent_at' => now(),
        ]);

        Queue::fake();

        $result = app(\App\Modules\Push\Services\PushDeliveryService::class)->dispatchPending(50);
        $this->assertSame(0, $result['dispatched']);
        Queue::assertNothingPushed();
    }

    private function registerDevice(User $user, string $deviceId, string $token): PushDevice
    {
        return app(PushDeviceService::class)->register($user, [
            'device_id' => $deviceId,
            'platform' => 'android',
            'token' => $token,
        ]);
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'ar',
        ]);
    }
}
