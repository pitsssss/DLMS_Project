<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Support\NotificationEventKey;
use App\Modules\Notifications\Support\NotificationEventMatrix;
use App\Modules\Notifications\Support\NotificationPayload;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_list_and_pagination_and_unread_only_remain_compatible(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);

        $service->sendToUser($citizen->id, 'A', 'a', NotificationType::ApplicationCreated->value, ['application_id' => 1]);
        $service->sendToUser($citizen->id, 'B', 'b', NotificationType::FineCreated->value, ['fine_id' => 2]);
        $read = $service->sendToUser($citizen->id, 'C', 'c', NotificationType::FinePaid->value, ['fine_id' => 3]);
        $service->markAsRead($citizen, $read->id);

        Sanctum::actingAs($citizen);

        $all = $this->getJson('/api/notifications?per_page=2')->assertOk();
        $this->assertCount(2, $all->json('data.items'));
        $this->assertSame(3, $all->json('data.pagination.total'));
        $this->assertArrayHasKey('is_read', $all->json('data.items.0'));
        $this->assertArrayHasKey('type', $all->json('data.items.0'));
        $this->assertArrayHasKey('data', $all->json('data.items.0'));
        $this->assertArrayNotHasKey('event_key', $all->json('data.items.0'));

        $unread = $this->getJson('/api/notifications?unread_only=1')->assertOk();
        $this->assertSame(2, $unread->json('data.pagination.total'));
    }

    public function test_registered_types_are_machine_values_not_localized_text(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->assertDoesNotMatchRegularExpression('/\s/u', $type->value);
            $this->assertStringContainsString('.', $type->value);
            $this->assertStringStartsWith('messages.notifications.', $type->titleKey());
            $this->assertStringStartsWith('messages.notifications.', $type->bodyKey());
        }

        $this->assertContains('payment.failed', NotificationEventMatrix::n2Types());
        $this->assertContains('license.issued', NotificationEventMatrix::n1Types());
    }

    public function test_payload_normalization_strips_disallowed_keys(): void
    {
        $normalized = NotificationPayload::normalize(NotificationType::DocumentRejected, [
            'application_id' => 10,
            'document_id' => 20,
            'national_id' => '123',
            'file_path' => 'secret/path.pdf',
            'rejection_reason_code' => 'unclear_document',
        ]);

        $this->assertSame([
            'application_id' => 10,
            'document_id' => 20,
            'rejection_reason_code' => 'unclear_document',
        ], $normalized);
    }

    public function test_status_transition_emits_registered_type_with_lean_data(): void
    {
        $citizen = $this->citizen();
        $application = $this->applicationFor($citizen, ApplicationStatus::Draft);

        app(ApplicationRepository::class)->transitionStatus(
            $application,
            ApplicationStatus::PaymentPending,
            null,
            'n1'
        );

        $notification = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', NotificationType::ApplicationPaymentPending->value)
            ->firstOrFail();

        $this->assertSame(NotificationType::ApplicationPaymentPending->value, $notification->type);
        $this->assertSame($application->id, $notification->data['application_id']);
        $this->assertArrayHasKey('status', $notification->data);
        $this->assertArrayNotHasKey('national_id', $notification->data ?? []);
        $this->assertNotNull($notification->event_key);
        $this->assertStringStartsWith('application.payment_pending:history:', $notification->event_key);
    }

    public function test_license_issued_status_does_not_emit_application_license_issued(): void
    {
        $citizen = $this->citizen();
        $application = $this->applicationFor($citizen, ApplicationStatus::Approved);

        app(ApplicationRepository::class)->transitionStatus(
            $application,
            ApplicationStatus::LicenseIssued,
            null,
            'n1 license status'
        );

        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::ApplicationLicenseIssued->value)
                ->count()
        );
        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::LicenseIssued->value)
                ->count()
        );
    }

    public function test_resource_contract_excludes_internal_event_key(): void
    {
        $citizen = $this->citizen();
        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::FineCreated,
            ['fine_id' => 99],
            ['amount' => 1, 'reason' => 'x'],
            NotificationEventKey::forFine(NotificationType::FineCreated, 99)
        );

        Sanctum::actingAs($citizen);
        $item = $this->getJson('/api/notifications')->assertOk()->json('data.items.0');

        $this->assertSame(NotificationType::FineCreated->value, $item['type']);
        $this->assertSame(['fine_id' => 99], $item['data']);
        $this->assertArrayNotHasKey('event_key', $item);
    }

    private function citizen(array $overrides = []): User
    {
        return User::factory()->withApprovedProfile()->create(array_merge([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'ar',
        ], $overrides));
    }

    private function applicationFor(User $citizen, ApplicationStatus $status): LicenseApplication
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return LicenseApplication::query()->create([
            'application_number' => 'APP-N1-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => $status,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ]);
    }
}
