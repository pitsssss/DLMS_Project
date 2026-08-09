<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\PushDeliveryStatus;
use App\Enums\UserType;
use App\Jobs\SendPushNotificationJob;
use App\Models\Notification;
use App\Models\PushDelivery;
use App\Models\PushDevice;
use App\Models\User;
use App\Modules\Devices\Services\PushDeviceService;
use App\Modules\Firebase\Services\FcmClient;
use App\Modules\Firebase\Support\FcmErrorCategory;
use App\Modules\Firebase\Support\FcmSendResult;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Push\Services\PushDeliveryService;
use App\Modules\Push\Support\PushNotificationPayload;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SendPushNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config([
            'firebase.push.enabled' => true,
            'firebase.push.tries' => 5,
            'firebase.push.backoff' => [60, 120, 300, 900],
        ]);
    }

    public function test_job_payload_contains_delivery_id_only(): void
    {
        $job = new SendPushNotificationJob(42);
        $serialized = serialize($job);

        $this->assertStringContainsString('pushDeliveryId', $serialized);
        $this->assertStringNotContainsString('fcm', strtolower($serialized));
        $this->assertSame(42, $job->pushDeliveryId);
    }

    public function test_successful_send_marks_delivery_sent(): void
    {
        [$delivery, $notification, $device] = $this->seedPendingDelivery('عنوان', 'نص');

        $this->mockFcm(FcmSendResult::success(200, 'projects/test/messages/abc'));

        (new SendPushNotificationJob($delivery->id))->handle(app(PushDeliveryService::class));

        $fresh = $delivery->fresh();
        $this->assertSame(PushDeliveryStatus::Sent, $fresh->status);
        $this->assertSame('projects/test/messages/abc', $fresh->provider_message_id);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(1, $fresh->attempts);
        $this->assertTrue(PushDevice::query()->whereKey($device->id)->exists());
    }

    public function test_push_uses_stored_arabic_title_body_without_retranslation(): void
    {
        $arTitle = Lang::get('messages.notifications.fine_issued_title', [], 'ar');
        $arBody = Lang::get('messages.notifications.fine_issued_body', ['amount' => '10', 'reason' => 'x'], 'ar');

        [$delivery] = $this->seedPendingDelivery($arTitle, $arBody, 'ar');

        $captured = null;
        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')
            ->once()
            ->andReturnUsing(function ($token, $title, $body, $data) use (&$captured) {
                $captured = compact('token', 'title', 'body', 'data');

                return FcmSendResult::success(200, 'projects/t/messages/1');
            });
        $this->app->instance(FcmClient::class, $mock);

        app()->setLocale('en');
        (new SendPushNotificationJob($delivery->id))->handle(app(PushDeliveryService::class));

        $this->assertSame($arTitle, $captured['title']);
        $this->assertSame($arBody, $captured['body']);
        $this->assertSame((string) $delivery->notification_id, $captured['data']['notification_id']);
        $this->assertSame(NotificationType::FineCreated->value, $captured['data']['type']);
        $this->assertSame('7', $captured['data']['fine_id']);
        $this->assertArrayNotHasKey('event_key', $captured['data']);
        $this->assertArrayNotHasKey('user_id', $captured['data']);
        $this->assertArrayNotHasKey('token', $captured['data']);
    }

    public function test_unregistered_marks_invalid_and_deletes_device(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();

        $this->mockFcm(FcmSendResult::failure(404, FcmErrorCategory::Unregistered, 'UNREGISTERED', 'NOT_FOUND'));

        (new SendPushNotificationJob($delivery->id))->handle(app(PushDeliveryService::class));

        $this->assertSame(PushDeliveryStatus::InvalidToken, $delivery->fresh()->status);
        $this->assertSame(0, PushDevice::query()->whereKey($device->id)->count());
    }

    public function test_token_rotation_race_does_not_delete_new_token(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();
        $oldHash = $device->token_hash;

        $this->mockFcm(FcmSendResult::failure(404, FcmErrorCategory::Unregistered, 'UNREGISTERED', 'NOT_FOUND'));

        // Simulate rotation after claim would capture old hash — process deletes by captured hash.
        // Rotate device BEFORE process: claim uses CURRENT token; UNREGISTERED then deletes current.
        // Race case: claim with old hash, then rotate, then deleteByIdAndTokenHash(old) should no-op.
        $service = app(PushDeliveryService::class);

        // Manually exercise delete race: rotate first, then delete with old hash.
        app(PushDeviceService::class)->register($device->user, [
            'device_id' => $device->device_id,
            'platform' => 'android',
            'token' => 'brand-new-token',
        ]);
        $rotated = $device->fresh();
        $this->assertNotSame($oldHash, $rotated->token_hash);

        $deleted = app(\App\Modules\Devices\Repositories\PushDeviceRepository::class)
            ->deleteByIdAndTokenHash($device->id, $oldHash);
        $this->assertSame(0, $deleted);
        $this->assertTrue(PushDevice::query()->whereKey($device->id)->exists());

        // Full job path with UNREGISTERED on current token still removes current registration.
        $delivery2 = PushDelivery::query()->create([
            'notification_id' => $delivery->notification_id,
            'push_device_id' => $rotated->id,
            'delivery_key' => 'notification:'.$delivery->notification_id.':device:race-'.$rotated->id,
            'status' => PushDeliveryStatus::Pending,
            'attempts' => 0,
        ]);
        (new SendPushNotificationJob($delivery2->id))->handle($service);
        $this->assertSame(0, PushDevice::query()->whereKey($rotated->id)->count());
    }

    public function test_permanent_failure_does_not_delete_device_or_retry(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();
        $this->mockFcm(FcmSendResult::failure(400, FcmErrorCategory::InvalidArgument, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT'));

        $result = app(PushDeliveryService::class)->processDelivery($delivery->id);

        $this->assertSame('failed', $result['outcome']);
        $this->assertSame(PushDeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertTrue(PushDevice::query()->whereKey($device->id)->exists());
    }

    public function test_retryable_failure_releases_job_and_keeps_pending(): void
    {
        [$delivery] = $this->seedPendingDelivery();
        $this->mockFcm(FcmSendResult::failure(
            503,
            FcmErrorCategory::Server,
            null,
            'UNAVAILABLE',
            false,
            120,
        ));

        $job = new class($delivery->id) extends SendPushNotificationJob
        {
            public ?int $releasedFor = null;

            public function release($delay = 0): void
            {
                $this->releasedFor = (int) $delay;
            }

            public function attempts(): int
            {
                return 1;
            }
        };

        $job->handle(app(PushDeliveryService::class));

        $this->assertSame(120, $job->releasedFor);
        $this->assertSame(PushDeliveryStatus::Pending, $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_deleted_device_before_processing_is_safe(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();
        $device->delete();

        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')->never();
        $this->app->instance(FcmClient::class, $mock);

        (new SendPushNotificationJob($delivery->id))->handle(app(PushDeliveryService::class));

        $fresh = $delivery->fresh();
        $this->assertSame(PushDeliveryStatus::InvalidToken, $fresh->status);
        $this->assertSame(0, $fresh->attempts); // no FCM call → no provider attempt
    }

    public function test_token_rotation_during_unregistered_does_not_delete_new_token(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();
        $citizen = $device->user;
        $oldHash = $device->token_hash;

        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')
            ->once()
            ->andReturnUsing(function () use ($citizen, $device) {
                app(PushDeviceService::class)->register($citizen, [
                    'device_id' => $device->device_id,
                    'platform' => 'android',
                    'token' => 'rotated-while-in-flight',
                ]);

                return FcmSendResult::failure(404, FcmErrorCategory::Unregistered, 'UNREGISTERED', 'NOT_FOUND');
            });
        $this->app->instance(FcmClient::class, $mock);

        (new SendPushNotificationJob($delivery->id))->handle(app(PushDeliveryService::class));

        $rotated = PushDevice::query()->whereKey($device->id)->first();
        $this->assertNotNull($rotated);
        $this->assertNotSame($oldHash, $rotated->token_hash);
        $this->assertSame('rotated-while-in-flight', $rotated->token);
        $this->assertSame(PushDeliveryStatus::InvalidToken, $delivery->fresh()->status);
    }

    public function test_token_rotation_before_execution_uses_current_token(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();
        app(PushDeviceService::class)->register($device->user, [
            'device_id' => $device->device_id,
            'platform' => 'android',
            'token' => 'current-token-after-rotate',
        ]);

        $used = null;
        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')
            ->once()
            ->andReturnUsing(function ($token) use (&$used) {
                $used = $token;

                return FcmSendResult::success(200, 'projects/t/messages/1');
            });
        $this->app->instance(FcmClient::class, $mock);

        (new SendPushNotificationJob($delivery->id))->handle(app(PushDeliveryService::class));

        $this->assertSame('current-token-after-rotate', $used);
        $this->assertSame(PushDeliveryStatus::Sent, $delivery->fresh()->status);
    }

    public function test_authentication_failure_does_not_delete_device(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();
        $this->mockFcm(FcmSendResult::failure(401, FcmErrorCategory::Authentication));

        $result = app(PushDeliveryService::class)->processDelivery($delivery->id);

        $this->assertSame('failed', $result['outcome']);
        $this->assertTrue(PushDevice::query()->whereKey($device->id)->exists());
        $this->assertSame(PushDeliveryStatus::Failed, $delivery->fresh()->status);
    }

    public function test_payload_builder_stringifies_and_strips_forbidden_keys(): void
    {
        $data = PushNotificationPayload::buildData(9, 'fine.created', [
            'fine_id' => 3,
            'event_key' => 'secret',
            'user_id' => 1,
            'token' => 'nope',
            'nested' => ['a' => 1],
        ]);

        $this->assertSame('9', $data['notification_id']);
        $this->assertSame('fine.created', $data['type']);
        $this->assertSame('3', $data['fine_id']);
        $this->assertArrayNotHasKey('event_key', $data);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('nested', $data);
    }

    public function test_token_not_logged_on_failure(): void
    {
        Log::spy();
        [$delivery, , $device] = $this->seedPendingDelivery();
        $token = $device->token;

        $this->mockFcm(FcmSendResult::failure(400, FcmErrorCategory::InvalidArgument));
        (new SendPushNotificationJob($delivery->id))->handle(app(PushDeliveryService::class));

        Log::shouldNotHaveReceived('warning', function (...$args) use ($token) {
            return str_contains((string) json_encode($args), $token);
        });
        Log::shouldNotHaveReceived('error', function (...$args) use ($token) {
            return str_contains((string) json_encode($args), $token);
        });
    }

    public function test_no_public_send_routes(): void
    {
        $this->postJson('/api/push/send')->assertNotFound();
        $this->postJson('/api/firebase/send')->assertNotFound();
        $this->postJson('/api/notifications/test-push')->assertNotFound();
    }

    /**
     * @return array{0: PushDelivery, 1: Notification, 2: PushDevice}
     */
    private function seedPendingDelivery(string $title = 'Title', string $body = 'Body', string $language = 'en'): array
    {
        Queue::fake();
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => $language,
        ]);
        $device = app(PushDeviceService::class)->register($citizen, [
            'device_id' => 'install-1',
            'platform' => 'android',
            'token' => 'fcm-token-secret-'.$citizen->id,
        ]);

        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            $title,
            $body,
            NotificationType::FineCreated->value,
            ['fine_id' => 7],
            null,
        );

        // sendToUser plans push when enabled; use that delivery or create one.
        $delivery = PushDelivery::query()->where('notification_id', $notification->id)->first();
        if ($delivery === null) {
            $delivery = PushDelivery::query()->create([
                'notification_id' => $notification->id,
                'push_device_id' => $device->id,
                'delivery_key' => PushDelivery::deliveryKey($notification->id, $device->id),
                'status' => PushDeliveryStatus::Pending,
                'attempts' => 0,
            ]);
        } else {
            $delivery->update(['status' => PushDeliveryStatus::Pending, 'attempts' => 0]);
        }

        return [$delivery->fresh(), $notification, $device->fresh()];
    }

    private function mockFcm(FcmSendResult $result): void
    {
        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')->andReturn($result);
        $this->app->instance(FcmClient::class, $mock);
    }
}
