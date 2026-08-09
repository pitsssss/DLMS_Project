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
use Illuminate\Support\Facades\Lang;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * N1 localization guards (complements RecipientNotificationLocaleTest).
 */
class NotificationLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_notify_uses_recipient_language_not_request_locale(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'ar',
        ]);

        Sanctum::actingAs($citizen);
        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        app()->setLocale('en');

        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::ApplicationApproved,
            ['application_id' => 1, 'application_number' => 'APP-1', 'status' => 'approved']
        );

        $notification = Notification::query()->where('user_id', $citizen->id)->firstOrFail();
        $this->assertSame(Lang::get('messages.notifications.approved_title', [], 'ar'), $notification->title);
        $this->assertStringNotContainsString('messages.', $notification->title.$notification->body);
    }

    public function test_historical_notification_does_not_retranslate(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'ar',
        ]);

        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            'عنوان ثابت',
            'نص ثابت',
            NotificationType::FineCreated->value,
            ['fine_id' => 1]
        );

        $citizen->update(['language' => 'en']);
        Sanctum::actingAs($citizen->fresh());

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'عنوان ثابت')
            ->assertJsonPath('data.items.0.body', 'نص ثابت');

        $this->assertSame('عنوان ثابت', $notification->fresh()->title);
    }
}
