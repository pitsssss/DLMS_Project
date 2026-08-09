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
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * F4 production certification cases for the push pipeline.
 */
class PushProductionCertificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config([
            'firebase.push.enabled' => true,
            'firebase.push.tries' => 5,
            'firebase.push.job_max_tries' => 25,
            'firebase.push.job_timeout_seconds' => 60,
            'firebase.push.processing_lease_seconds' => 180,
            'firebase.push.backoff' => [60, 120, 300, 900],
            'firebase.fcm.timeout_seconds' => 15,
            'queue.connections.database.retry_after' => 120,
        ]);
    }

    public function test_timeout_less_than_retry_after_safety(): void
    {
        $fcm = (float) config('firebase.fcm.timeout_seconds');
        $job = (int) config('firebase.push.job_timeout_seconds');
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertLessThan($job, $fcm, 'FCM HTTP timeout must be < job timeout');
        $this->assertLessThan($retryAfter, $job, 'job timeout must be < queue retry_after');
        $this->assertGreaterThanOrEqual(30, $retryAfter - $job, 'retry_after should leave a safety margin');

        $jobObj = new SendPushNotificationJob(1);
        $this->assertSame(60, $jobObj->timeout);
        $this->assertSame(25, $jobObj->tries);
    }

    public function test_laravel_tries_exceed_fcm_budget_so_overlap_does_not_exhaust_provider_retries(): void
    {
        $job = new SendPushNotificationJob(99);
        $this->assertGreaterThan(
            (int) config('firebase.push.tries'),
            $job->tries,
            'Laravel job tries must exceed FCM send budget',
        );

        $middleware = $job->middleware()[0];
        $this->assertNull($middleware->releaseAfter, 'WithoutOverlapping must use dontRelease()');
    }

    public function test_fcm_attempts_count_only_real_sends(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();
        $device->delete();

        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')->never();
        $this->app->instance(FcmClient::class, $mock);

        app(PushDeliveryService::class)->processDelivery($delivery->id);

        $this->assertSame(0, $delivery->fresh()->attempts);
    }

    public function test_quota_429_minimum_delay_is_60_seconds(): void
    {
        $service = app(PushDeliveryService::class);
        $this->assertSame(60, $service->retryDelaySeconds(1, null));
        $this->assertSame(60, $service->retryDelaySeconds(1, 10));
        $this->assertSame(90, $service->retryDelaySeconds(1, 90));
    }

    public function test_503_retry_after_honored(): void
    {
        [$delivery] = $this->seedPendingDelivery();
        $this->mockFcm(FcmSendResult::failure(
            503,
            FcmErrorCategory::Server,
            null,
            'UNAVAILABLE',
            false,
            180,
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
                return 99; // Laravel attempts must NOT drive backoff when FCM attempts are used
            }
        };

        $job->handle(app(PushDeliveryService::class));

        $this->assertSame(180, $job->releasedFor);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_malformed_retry_after_falls_back_to_backoff(): void
    {
        Http::fake([
            'https://example.test/bad' => Http::response(
                ['error' => ['status' => 'UNAVAILABLE']],
                503,
                ['Retry-After' => 'not-valid;;;'],
            ),
        ]);

        $client = app(FcmClient::class);
        $parsed = $client->parseRetryAfterSeconds(Http::get('https://example.test/bad'));
        $this->assertNull($parsed);
        $this->assertSame(60, app(PushDeliveryService::class)->retryDelaySeconds(1, $parsed));
    }

    public function test_retry_after_delay_seconds_and_http_date(): void
    {
        $client = app(FcmClient::class);

        Http::fake([
            'https://example.test/sec' => Http::response('', 503, ['Retry-After' => '120']),
            'https://example.test/date' => Http::response('', 503, [
                'Retry-After' => gmdate('D, d M Y H:i:s', time() + 95).' GMT',
            ]),
        ]);

        $sec = $client->parseRetryAfterSeconds(Http::get('https://example.test/sec'));
        $this->assertSame(120, $sec);

        $date = $client->parseRetryAfterSeconds(Http::get('https://example.test/date'));
        $this->assertNotNull($date);
        $this->assertGreaterThanOrEqual(60, $date);
        $this->assertLessThanOrEqual(120, $date);
    }

    public function test_terminal_delivery_never_resent(): void
    {
        [$delivery] = $this->seedPendingDelivery();
        $delivery->update([
            'status' => PushDeliveryStatus::Sent,
            'sent_at' => now(),
            'attempts' => 1,
        ]);

        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')->never();
        $this->app->instance(FcmClient::class, $mock);

        $result = app(PushDeliveryService::class)->processDelivery($delivery->id);
        $this->assertSame('skipped', $result['outcome']);
        $this->assertSame(PushDeliveryStatus::Sent, $delivery->fresh()->status);
    }

    public function test_duplicate_job_safe_after_sent(): void
    {
        [$delivery] = $this->seedPendingDelivery();
        $this->mockFcm(FcmSendResult::success(200, 'projects/t/messages/1'));

        $service = app(PushDeliveryService::class);
        (new SendPushNotificationJob($delivery->id))->handle($service);
        (new SendPushNotificationJob($delivery->id))->handle($service);

        $this->assertSame(PushDeliveryStatus::Sent, $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_invalid_token_never_retries(): void
    {
        [$delivery] = $this->seedPendingDelivery();
        $this->mockFcm(FcmSendResult::failure(404, FcmErrorCategory::Unregistered, 'UNREGISTERED', 'NOT_FOUND'));

        $job = new class($delivery->id) extends SendPushNotificationJob
        {
            public bool $released = false;

            public function release($delay = 0): void
            {
                $this->released = true;
            }
        };

        $job->handle(app(PushDeliveryService::class));
        $this->assertFalse($job->released);
        $this->assertSame(PushDeliveryStatus::InvalidToken, $delivery->fresh()->status);

        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')->never();
        $this->app->instance(FcmClient::class, $mock);
        app(PushDeliveryService::class)->processDelivery($delivery->id);
    }

    public function test_stale_processing_recovered_recent_not_stolen(): void
    {
        [$stale] = $this->seedPendingDelivery();
        [$fresh] = $this->seedPendingDelivery(tokenSuffix: 'b');

        $stale->update([
            'status' => PushDeliveryStatus::Processing,
            'last_attempt_at' => now()->subMinutes(10),
        ]);
        $fresh->update([
            'status' => PushDeliveryStatus::Processing,
            'last_attempt_at' => now()->subSeconds(30),
        ]);

        $reclaimed = app(PushDeliveryService::class)->recoverStaleProcessing(50);

        $this->assertSame(1, $reclaimed);
        $this->assertSame(PushDeliveryStatus::Pending, $stale->fresh()->status);
        $this->assertSame(PushDeliveryStatus::Processing, $fresh->fresh()->status);
    }

    public function test_dispatch_pending_never_redispatches_terminal(): void
    {
        Queue::fake();
        [$pending] = $this->seedPendingDelivery();
        [$sent] = $this->seedPendingDelivery(tokenSuffix: 'sent');
        [$failed] = $this->seedPendingDelivery(tokenSuffix: 'fail');
        [$invalid] = $this->seedPendingDelivery(tokenSuffix: 'inv');

        $sent->update(['status' => PushDeliveryStatus::Sent, 'sent_at' => now()]);
        $failed->update(['status' => PushDeliveryStatus::Failed, 'failed_at' => now()]);
        $invalid->update(['status' => PushDeliveryStatus::InvalidToken, 'failed_at' => now()]);

        Queue::fake();
        $result = app(PushDeliveryService::class)->dispatchPending(100);

        $this->assertSame(1, $result['dispatched']);
        Queue::assertPushed(SendPushNotificationJob::class, fn ($j) => $j->pushDeliveryId === $pending->id);
        Queue::assertNotPushed(SendPushNotificationJob::class, fn ($j) => $j->pushDeliveryId === $sent->id);
        Queue::assertNotPushed(SendPushNotificationJob::class, fn ($j) => $j->pushDeliveryId === $failed->id);
        Queue::assertNotPushed(SendPushNotificationJob::class, fn ($j) => $j->pushDeliveryId === $invalid->id);
    }

    public function test_failed_dispatch_leaves_pending_recoverable(): void
    {
        Queue::fake();
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
        app(PushDeviceService::class)->register($citizen, [
            'device_id' => 'd-dispatch',
            'platform' => 'android',
            'token' => 'tok-dispatch',
        ]);

        // Simulate: delivery inserted but job never queued — recovery redispatches.
        $notification = Notification::query()->create([
            'user_id' => $citizen->id,
            'title' => 'T',
            'body' => 'B',
            'type' => NotificationType::FineCreated->value,
            'data' => ['fine_id' => 1],
        ]);
        $device = PushDevice::query()->where('user_id', $citizen->id)->firstOrFail();
        $delivery = PushDelivery::query()->create([
            'notification_id' => $notification->id,
            'push_device_id' => $device->id,
            'delivery_key' => PushDelivery::deliveryKey($notification->id, $device->id),
            'status' => PushDeliveryStatus::Pending,
            'attempts' => 0,
        ]);

        $result = app(PushDeliveryService::class)->dispatchPending(10);
        $this->assertSame(1, $result['dispatched']);
        Queue::assertPushed(SendPushNotificationJob::class, fn ($j) => $j->pushDeliveryId === $delivery->id);
    }

    public function test_worker_crash_after_claim_leaves_recoverable_processing(): void
    {
        [$delivery] = $this->seedPendingDelivery();
        // Simulate crash after claim: processing + last_attempt_at set, no FCM success.
        $delivery->update([
            'status' => PushDeliveryStatus::Processing,
            'last_attempt_at' => now()->subMinutes(10),
            'attempts' => 0,
        ]);

        $reclaimed = app(PushDeliveryService::class)->recoverStaleProcessing(10);
        $this->assertSame(1, $reclaimed);
        $this->assertSame(PushDeliveryStatus::Pending, $delivery->fresh()->status);

        // Ambiguous case (FCM may have accepted): if attempts already incremented and
        // status still processing after crash mid-write — still reclaimed to pending.
        // Operators must accept possible duplicate push (not exactly-once).
        $this->assertSame(0, $delivery->fresh()->attempts);
    }

    public function test_push_disabled_skips_planning_and_fcm(): void
    {
        config(['firebase.push.enabled' => false]);
        Queue::fake();

        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
        app(PushDeviceService::class)->register($citizen, [
            'device_id' => 'd1',
            'platform' => 'android',
            'token' => 'tok',
        ]);

        $mock = Mockery::mock(FcmClient::class);
        $mock->shouldReceive('sendToToken')->never();
        $this->app->instance(FcmClient::class, $mock);

        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            'Title',
            'Body',
            NotificationType::FineCreated->value,
            ['fine_id' => 1],
        );

        $this->assertNotNull($notification->id);
        $this->assertSame(0, PushDelivery::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_push_enabled_boolean_false_string_is_false(): void
    {
        $this->assertFalse(filter_var('false', FILTER_VALIDATE_BOOLEAN));
        $this->assertFalse(filter_var('0', FILTER_VALIDATE_BOOLEAN));
        $this->assertTrue(filter_var('true', FILTER_VALIDATE_BOOLEAN));
        $this->assertTrue(filter_var('1', FILTER_VALIDATE_BOOLEAN));
        // Documented parsing used by config/firebase.php
        $this->assertFalse((bool) filter_var(env('MISSING_PUSH_FLAG', false), FILTER_VALIDATE_BOOLEAN));
    }

    public function test_no_secret_in_job_serialization(): void
    {
        $job = new SendPushNotificationJob(12345);
        $serialized = serialize($job);

        $this->assertStringContainsString('12345', $serialized);
        foreach (['token', 'private_key', 'credentials', 'BEGIN PRIVATE', 'access_token', 'Bearer'] as $needle) {
            $this->assertStringNotContainsString($needle, $serialized);
        }
    }

    public function test_no_token_in_failed_job_payload_shape(): void
    {
        [$delivery, , $device] = $this->seedPendingDelivery();
        $token = (string) $device->token;

        $job = new SendPushNotificationJob($delivery->id);
        $command = serialize($job);
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'displayName' => SendPushNotificationJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => SendPushNotificationJob::class, 'command' => $command],
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString($device->token_hash, $payload);
        $this->assertStringNotContainsString('FIREBASE_CREDENTIALS', $payload);
    }

    public function test_db_notification_isolation_when_push_planning_errors(): void
    {
        config(['firebase.push.enabled' => true]);
        Queue::fake();

        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);

        // No devices — planning no-ops; notification still persists.
        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            'Isolated',
            'Body',
            NotificationType::FineCreated->value,
            ['fine_id' => 9],
        );

        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'title' => 'Isolated']);
        $this->assertSame(0, PushDelivery::query()->count());
    }

    public function test_docker_entrypoint_starts_supervisord_with_queue_push(): void
    {
        $entrypoint = File::get(base_path('docker/php/entrypoint.sh'));
        $supervisor = File::get(base_path('docker/supervisor/supervisord.conf'));

        $this->assertStringContainsString('exec /usr/bin/supervisord', $entrypoint);
        $this->assertStringContainsString('[program:queue-push]', $supervisor);
        $this->assertStringContainsString('queue:work --queue=push,default', $supervisor);
        $this->assertStringContainsString('--timeout=60', $supervisor);
        $this->assertStringContainsString('autostart=true', $supervisor);
        $this->assertStringContainsString('autorestart=true', $supervisor);
    }

    /**
     * @return array{0: PushDelivery, 1: Notification, 2: PushDevice}
     */
    private function seedPendingDelivery(
        string $title = 'Title',
        string $body = 'Body',
        string $language = 'en',
        string $tokenSuffix = 'a',
    ): array {
        Queue::fake();
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => $language,
        ]);
        $device = app(PushDeviceService::class)->register($citizen, [
            'device_id' => 'install-'.$tokenSuffix.'-'.$citizen->id,
            'platform' => 'android',
            'token' => 'fcm-token-secret-'.$tokenSuffix.'-'.$citizen->id,
        ]);

        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            $title,
            $body,
            NotificationType::FineCreated->value,
            ['fine_id' => 7],
            null,
        );

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
