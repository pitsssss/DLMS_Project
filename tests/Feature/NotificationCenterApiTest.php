<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Support\NotificationEventKey;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Lang;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * N3 citizen Notification Center list + routing + localization contract.
 */
class NotificationCenterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_n2_appointment_event_keys_do_not_collide_across_semantics(): void
    {
        $booked = NotificationEventKey::forAppointment(NotificationType::AppointmentBooked, 42);
        $cancelled = NotificationEventKey::forAppointment(NotificationType::AppointmentCancelled, 42);
        $rescheduleA = NotificationEventKey::forAppointmentReschedule(42, 7, now());
        $rescheduleB = NotificationEventKey::forAppointmentReschedule(42, 8, now()->addHour());

        $this->assertSame('appointment.booked:appointment:42', $booked);
        $this->assertSame('appointment.cancelled:appointment:42', $cancelled);
        $this->assertNotSame($booked, $cancelled);
        $this->assertNotSame($rescheduleA, $rescheduleB);
        $this->assertStringStartsWith('appointment.rescheduled:appointment:42:slot:', $rescheduleA);
    }

    public function test_n2_payment_failed_event_key_scopes_by_payment_and_code(): void
    {
        $a = NotificationEventKey::forPaymentCode(NotificationType::PaymentFailed, 10, 'card_declined');
        $b = NotificationEventKey::forPaymentCode(NotificationType::PaymentFailed, 10, 'insufficient_funds');
        $c = NotificationEventKey::forPaymentCode(NotificationType::PaymentFailed, 11, 'card_declined');

        $this->assertSame('payment.failed:payment:10:code:card_declined', $a);
        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_list_returns_only_own_notifications_newest_first(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();
        $service = app(NotificationService::class);

        $service->sendToUser($other->id, 'Other', 'x', NotificationType::FineCreated->value, ['fine_id' => 9]);
        $first = $service->sendToUser($owner->id, 'Older', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);
        $second = $service->sendToUser($owner->id, 'Newer', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/notifications')->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame($second->id, $items[0]['id']);
        $this->assertSame($first->id, $items[1]['id']);
        $this->assertSame(['Newer', 'Older'], array_column($items, 'title'));
        $this->assertArrayNotHasKey('event_key', $items[0]);
        $this->assertArrayNotHasKey('user_id', $items[0]);
    }

    public function test_list_pagination_default_and_max_per_page(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);

        for ($i = 1; $i <= 25; $i++) {
            $service->sendToUser($citizen->id, "N{$i}", 'b', NotificationType::FineCreated->value, ['fine_id' => $i]);
        }

        Sanctum::actingAs($citizen);

        $default = $this->getJson('/api/notifications')->assertOk();
        $this->assertCount(20, $default->json('data.items'));
        $this->assertSame(20, $default->json('data.pagination.per_page'));
        $this->assertSame(25, $default->json('data.pagination.total'));

        $this->getJson('/api/notifications?per_page=100')->assertOk()
            ->assertJsonPath('data.pagination.per_page', 100);

        $this->getJson('/api/notifications?per_page=101')->assertStatus(422);
        $this->getJson('/api/notifications?per_page=0')->assertStatus(422);
    }

    public function test_unread_only_filter_remains_compatible(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);

        $service->sendToUser($citizen->id, 'Unread', 'a', NotificationType::FineCreated->value, ['fine_id' => 1]);
        $read = $service->sendToUser($citizen->id, 'Read', 'b', NotificationType::FinePaid->value, ['fine_id' => 2]);
        $service->markAsRead($citizen, $read->id);

        Sanctum::actingAs($citizen);

        $unread = $this->getJson('/api/notifications?unread_only=1')->assertOk();
        $this->assertSame(1, $unread->json('data.pagination.total'));
        $this->assertSame('Unread', $unread->json('data.items.0.title'));

        $all = $this->getJson('/api/notifications?unread_only=0')->assertOk();
        $this->assertSame(2, $all->json('data.pagination.total'));
    }

    public function test_mark_one_owner_foreign_and_idempotent(): void
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
        $this->putJson('/api/notifications/'.$notification->id.'/read')->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);

        Sanctum::actingAs($owner);
        $first = $this->putJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $readAt = $first->json('data.read_at');
        $this->assertNotNull($readAt);

        $second = $this->putJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertSame($readAt, $second->json('data.read_at'));
        $this->assertSame('Private', $notification->fresh()->title);
        $this->assertSame(NotificationType::FineCreated->value, $notification->fresh()->type);
    }

    public function test_read_all_route_does_not_collide_with_mark_one(): void
    {
        $citizen = $this->citizen();
        app(NotificationService::class)->sendToUser(
            $citizen->id,
            'A',
            'b',
            NotificationType::FineCreated->value,
            ['fine_id' => 1]
        );

        Sanctum::actingAs($citizen);

        $this->putJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read_count', 1)
            ->assertJsonPath('data.unread_count', 0);

        $notification = Notification::query()->where('user_id', $citizen->id)->firstOrFail();
        $this->putJson('/api/notifications/'.$notification->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
    }

    public function test_envelope_messages_localize_without_leaking_keys_or_mutating_history(): void
    {
        $citizen = $this->citizen();
        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            'عنوان ثابت',
            'نص ثابت',
            NotificationType::FineCreated->value,
            ['fine_id' => 1]
        );

        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/notifications/unread-count')
            ->assertOk();
        $this->assertSame(Lang::get('messages.notifications.unread_count', [], 'ar'), $ar->json('message'));
        $this->assertStringNotContainsString('messages.', (string) $ar->json('message'));

        $en = $this->withHeader('Accept-Language', 'en')
            ->putJson('/api/notifications/read-all')
            ->assertOk();
        $this->assertSame(Lang::get('messages.notifications.read_all', [], 'en'), $en->json('message'));
        $this->assertStringNotContainsString('messages.', (string) $en->json('message'));

        $this->assertSame('عنوان ثابت', $notification->fresh()->title);
        $this->assertSame('نص ثابت', $notification->fresh()->body);
    }

    public function test_unauthenticated_and_employee_are_rejected(): void
    {
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->putJson('/api/notifications/read-all')->assertUnauthorized();
        $this->getJson('/api/notifications')->assertUnauthorized();

        $employee = User::factory()->dashboardEmployee()->create();
        Sanctum::actingAs($employee);

        $this->getJson('/api/notifications/unread-count')->assertForbidden();
        $this->putJson('/api/notifications/read-all')->assertForbidden();
        $this->getJson('/api/notifications')->assertForbidden();
    }

    public function test_user_id_in_request_cannot_affect_another_citizen(): void
    {
        $a = $this->citizen();
        $b = $this->citizen();
        app(NotificationService::class)->sendToUser(
            $b->id,
            'B',
            'x',
            NotificationType::FineCreated->value,
            ['fine_id' => 1]
        );

        Sanctum::actingAs($a);
        $this->putJson('/api/notifications/read-all', ['user_id' => $b->id])
            ->assertOk()
            ->assertJsonPath('data.marked_read_count', 0);

        $this->assertNull(Notification::query()->where('user_id', $b->id)->value('read_at'));
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
    }
}
