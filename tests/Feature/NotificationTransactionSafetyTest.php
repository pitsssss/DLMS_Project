<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\Fine;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Fines\Services\FineService;
use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Support\NotificationEventKey;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class NotificationTransactionSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    public function test_rolled_back_business_transaction_creates_no_notification(): void
    {
        $citizen = $this->citizen();

        try {
            DB::transaction(function () use ($citizen): void {
                app(NotificationService::class)->notify(
                    $citizen->id,
                    NotificationType::FineCreated,
                    ['fine_id' => 1],
                    ['amount' => 10, 'reason' => 'x'],
                    NotificationEventKey::forFine(NotificationType::FineCreated, 1)
                );

                throw new \RuntimeException('domain rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Notification::query()->where('user_id', $citizen->id)->count());
    }

    public function test_notification_persist_failure_does_not_roll_back_business_success(): void
    {
        $citizen = $this->citizen();
        $admin = User::factory()->dashboardAdmin('admin')->create();

        $logged = [];
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$logged): bool {
                $logged = ['message' => $message, 'context' => $context];

                return $message === 'notification.persist_failed';
            });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $repo = Mockery::mock(NotificationRepository::class);
        $repo->shouldReceive('findByEventKey')->andReturn(null);
        $repo->shouldReceive('create')->andThrow(new \RuntimeException('persist failed'));
        $this->app->instance(NotificationRepository::class, $repo);
        $this->app->forgetInstance(NotificationService::class);
        $this->app->forgetInstance(FineService::class);

        $fine = app(FineService::class)->create($admin, $citizen->id, 5000, 'Speeding');

        $this->assertInstanceOf(Fine::class, $fine);
        $this->assertDatabaseHas('fines', ['id' => $fine->id, 'citizen_id' => $citizen->id]);
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(NotificationType::FineCreated->value, $logged['context']['type'] ?? null);
        $this->assertSame($citizen->id, $logged['context']['user_id'] ?? null);
        $this->assertSame(\RuntimeException::class, $logged['context']['exception'] ?? null);
    }

    public function test_notify_failure_is_logged_without_sensitive_payload(): void
    {
        $loggedContext = null;
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
                $loggedContext = $context;

                return $message === 'notification.persist_failed';
            });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $repo = Mockery::mock(NotificationRepository::class);
        $repo->shouldReceive('findByEventKey')->andReturn(null);
        $repo->shouldReceive('create')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(NotificationRepository::class, $repo);
        $this->app->forgetInstance(NotificationService::class);

        app(NotificationService::class)->notify(
            42,
            NotificationType::DocumentRejected,
            [
                'application_id' => 7,
                'document_id' => 9,
                'rejection_details' => 'should-not-be-logged',
            ],
            [],
            'document.rejected:document:9:review:1'
        );

        $this->assertNotNull($loggedContext);
        $this->assertSame(NotificationType::DocumentRejected->value, $loggedContext['type']);
        $this->assertSame(9, $loggedContext['entity_ids']['document_id']);
        $this->assertStringNotContainsString('should-not-be-logged', json_encode($loggedContext));
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
    }
}
