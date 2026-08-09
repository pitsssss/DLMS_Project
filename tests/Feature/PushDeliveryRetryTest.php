<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\PushDeliveryStatus;
use App\Enums\UserType;
use App\Jobs\SendPushNotificationJob;
use App\Models\PushDelivery;
use App\Models\User;
use App\Modules\Devices\Services\PushDeviceService;
use App\Modules\Firebase\Services\FcmClient;
use App\Modules\Firebase\Support\FcmErrorCategory;
use App\Modules\Firebase\Support\FcmSendResult;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Push\Services\PushDeliveryService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PushDeliveryRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config([
            'firebase.push.enabled' => true,
            'firebase.push.tries' => 3,
            'firebase.push.backoff' => [60, 120, 300],
        ]);
    }

    public function test_retry_delay_uses_backoff_and_honors_retry_after_minimum(): void
    {
        $service = app(PushDeliveryService::class);
        $this->assertSame(60, $service->retryDelaySeconds(1));
        $this->assertSame(120, $service->retryDelaySeconds(2));
        $this->assertSame(300, $service->retryDelaySeconds(3));
        $this->assertSame(180, $service->retryDelaySeconds(1, 180));
        $this->assertSame(60, $service->retryDelaySeconds(1, 10)); // floor at 60
    }

    public function test_retries_exhausted_marks_failed(): void
    {
        Queue::fake();
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
        $device = app(PushDeviceService::class)->register($citizen, [
            'device_id' => 'd1',
            'platform' => 'ios',
            'token' => 'tok-retry',
        ]);
        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            'T',
            'B',
            NotificationType::FineCreated->value,
            ['fine_id' => 1],
        );
        $delivery = PushDelivery::query()->where('notification_id', $notification->id)->firstOrFail();
        $delivery->update(['attempts' => 2, 'status' => PushDeliveryStatus::Pending]);

        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')->once()->andReturn(
            FcmSendResult::failure(503, FcmErrorCategory::Server, null, 'UNAVAILABLE')
        );
        $this->app->instance(FcmClient::class, $mock);

        $result = app(PushDeliveryService::class)->processDelivery($delivery->id);
        $this->assertSame('failed', $result['outcome']);
        $this->assertSame(PushDeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertSame(3, $delivery->fresh()->attempts);
        $this->assertNotNull($delivery->fresh()->failed_at);
    }

    public function test_dispatch_pending_command_dispatches_only_pending(): void
    {
        Queue::fake();
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
        $device = app(PushDeviceService::class)->register($citizen, [
            'device_id' => 'd1',
            'platform' => 'android',
            'token' => 'tok',
        ]);
        $n1 = app(NotificationService::class)->sendToUser($citizen->id, 'A', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);
        $n2 = app(NotificationService::class)->sendToUser($citizen->id, 'B', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);

        $pending = PushDelivery::query()->where('notification_id', $n1->id)->firstOrFail();
        $sent = PushDelivery::query()->where('notification_id', $n2->id)->firstOrFail();
        $sent->update(['status' => PushDeliveryStatus::Sent, 'sent_at' => now()]);

        // Clear jobs from initial planning.
        Queue::fake();

        $this->artisan('push:dispatch-pending', ['--limit' => 50])
            ->assertSuccessful();

        Queue::assertPushed(SendPushNotificationJob::class, fn ($job) => $job->pushDeliveryId === $pending->id);
        Queue::assertNotPushed(SendPushNotificationJob::class, fn ($job) => $job->pushDeliveryId === $sent->id);
    }
}
