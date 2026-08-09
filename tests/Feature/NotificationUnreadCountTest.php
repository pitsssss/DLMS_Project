<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationUnreadCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_unread_count_returns_integer_and_ignores_read_and_foreign(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();
        $service = app(NotificationService::class);

        $service->sendToUser($owner->id, 'U1', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);
        $service->sendToUser($owner->id, 'U2', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);
        $read = $service->sendToUser($owner->id, 'R', 'c', NotificationType::FinePaid->value, ['fine_id' => 3]);
        $service->markAsRead($owner, $read->id);
        $service->sendToUser($other->id, 'X', 'd', NotificationType::FineCreated->value, ['fine_id' => 9]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonMissingPath('data.items');

        $this->assertIsInt($this->getJson('/api/notifications/unread-count')->json('data.unread_count'));
    }

    public function test_zero_unread_returns_zero(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_count_not_affected_by_list_pagination(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);

        for ($i = 1; $i <= 25; $i++) {
            $service->sendToUser($citizen->id, "N{$i}", 'b', NotificationType::FineCreated->value, ['fine_id' => $i]);
        }

        Sanctum::actingAs($citizen);
        $this->getJson('/api/notifications?per_page=5')->assertOk()
            ->assertJsonPath('data.pagination.per_page', 5);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 25);
    }

    public function test_mark_one_decreases_count_by_one(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);
        $n1 = $service->sendToUser($citizen->id, 'A', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);
        $service->sendToUser($citizen->id, 'B', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);

        Sanctum::actingAs($citizen);
        $this->assertSame(2, $this->getJson('/api/notifications/unread-count')->json('data.unread_count'));

        $this->putJson('/api/notifications/'.$n1->id.'/read')->assertOk();

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_read_all_sets_count_to_zero_for_existing_unread(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);
        $service->sendToUser($citizen->id, 'A', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);
        $service->sendToUser($citizen->id, 'B', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);

        Sanctum::actingAs($citizen);
        $this->putJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_unread_count_uses_single_aggregate_query(): void
    {
        $citizen = $this->citizen();
        app(NotificationService::class)->sendToUser(
            $citizen->id,
            'A',
            'a',
            NotificationType::FineCreated->value,
            ['fine_id' => 1]
        );

        Sanctum::actingAs($citizen);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/notifications/unread-count')->assertOk();
        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains(strtolower($sql), 'notifications'))
            ->values();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('count(', strtolower($queries[0]));
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
    }
}
