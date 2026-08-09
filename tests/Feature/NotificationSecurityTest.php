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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_list_is_citizen_scoped(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();

        app(NotificationService::class)->sendToUser(
            $owner->id,
            'Mine',
            'body',
            NotificationType::FineCreated->value,
            ['fine_id' => 1]
        );
        app(NotificationService::class)->sendToUser(
            $other->id,
            'Other',
            'body',
            NotificationType::FineCreated->value,
            ['fine_id' => 2]
        );

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/notifications')->assertOk();
        $ids = collect($response->json('data.items'))->pluck('title')->all();

        $this->assertSame(['Mine'], $ids);
    }

    public function test_mark_read_foreign_notification_returns_not_found(): void
    {
        $owner = $this->citizen();
        $intruder = $this->citizen();

        $notification = app(NotificationService::class)->sendToUser(
            $owner->id,
            'Private',
            'body',
            NotificationType::FineCreated->value,
            ['fine_id' => 1]
        );

        Sanctum::actingAs($intruder);
        $this->putJson('/api/notifications/'.$notification->id.'/read')
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_read_is_idempotent(): void
    {
        $citizen = $this->citizen();
        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            'Once',
            'body',
            NotificationType::FineCreated->value,
            ['fine_id' => 1]
        );

        Sanctum::actingAs($citizen);
        $first = $this->putJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $readAt = $first->json('data.read_at');
        $this->assertNotNull($readAt);

        $second = $this->putJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertSame($readAt, $second->json('data.read_at'));
        $this->assertTrue($second->json('data.is_read'));

        $fresh = $notification->fresh();
        $this->assertSame('Once', $fresh->title);
        $this->assertSame($citizen->id, $fresh->user_id);
        $this->assertSame(NotificationType::FineCreated->value, $fresh->type);
    }

    public function test_citizen_cannot_create_notification_via_api(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->postJson('/api/notifications', [
            'title' => 'hack',
            'body' => 'hack',
            'type' => 'fine.created',
            'user_id' => $citizen->id,
        ])->assertStatus(405);

        $this->assertSame(0, Notification::query()->count());
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
    }
}
