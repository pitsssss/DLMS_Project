<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationReadAllTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_read_all_marks_only_current_user_unread_and_is_idempotent(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();
        $service = app(NotificationService::class);

        $service->sendToUser($owner->id, 'U1', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);
        $service->sendToUser($owner->id, 'U2', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);
        $already = $service->sendToUser($owner->id, 'R', 'c', NotificationType::FinePaid->value, ['fine_id' => 3]);
        $priorReadAt = now()->subDay();
        $already->forceFill(['read_at' => $priorReadAt])->save();

        $foreign = $service->sendToUser($other->id, 'X', 'd', NotificationType::FineCreated->value, ['fine_id' => 9]);

        Sanctum::actingAs($owner);
        $first = $this->putJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(2, $first->json('data.marked_read_count'));
        $this->assertSame(0, $first->json('data.unread_count'));

        $this->assertNotNull(Notification::query()->where('user_id', $owner->id)->where('title', 'U1')->value('read_at'));
        $this->assertNotNull(Notification::query()->where('user_id', $owner->id)->where('title', 'U2')->value('read_at'));
        $this->assertSame(
            $priorReadAt->timestamp,
            $already->fresh()->read_at->timestamp,
            'Already-read rows must keep their original read_at'
        );
        $this->assertNull($foreign->fresh()->read_at);

        $second = $this->putJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $second->json('data.marked_read_count'));
        $this->assertSame(0, $second->json('data.unread_count'));
    }

    public function test_read_all_uses_bulk_update_not_id_prefetch(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);
        $service->sendToUser($citizen->id, 'A', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);
        $service->sendToUser($citizen->id, 'B', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);

        Sanctum::actingAs($citizen);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->putJson('/api/notifications/read-all')->assertOk();
        $notificationQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $sql) => strtolower($sql))
            ->filter(fn (string $sql) => str_contains($sql, 'notifications'))
            ->values();
        DB::disableQueryLog();

        $updates = $notificationQueries->filter(fn (string $sql) => str_starts_with(trim($sql), 'update'));
        $this->assertCount(1, $updates);
        $this->assertTrue(
            $notificationQueries->contains(fn (string $sql) => str_contains($sql, 'count(')),
            'Expected remaining unread_count aggregate after bulk update'
        );
        $this->assertFalse(
            $notificationQueries->contains(fn (string $sql) => str_contains($sql, 'select') && str_contains($sql, ' where ') && ! str_contains($sql, 'count(')),
            'read-all must not load notification IDs into memory'
        );
    }

    public function test_notification_created_after_read_all_remains_unread(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);
        $service->sendToUser($citizen->id, 'Old', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);

        Sanctum::actingAs($citizen);
        $this->putJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);

        $service->sendToUser($citizen->id, 'New', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->getJson('/api/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'New');
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
    }
}
