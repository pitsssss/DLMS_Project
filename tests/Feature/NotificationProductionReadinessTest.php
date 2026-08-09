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
use App\Modules\Dashboard\Services\DashboardCitizenService;
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
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use Tests\TestCase;

/**
 * N4 production-readiness certification for citizen in-app notifications.
 */
class NotificationProductionReadinessTest extends TestCase
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

    public function test_no_production_raw_notification_create_bypass(): void
    {
        $hits = [];
        $root = app_path();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR.'Notifications'.DIRECTORY_SEPARATOR.'Repositories'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = file_get_contents($path) ?: '';
            if (preg_match('/Notification::create\s*\(|new\s+Notification\s*\(/', $contents)) {
                $hits[] = $path;
            }
        }

        $this->assertSame([], $hits, 'Domain code must not bypass NotificationRepository create');
    }

    public function test_enum_matrix_and_translation_keys_are_synchronized(): void
    {
        $enumValues = array_map(static fn (NotificationType $t) => $t->value, NotificationType::cases());
        sort($enumValues);

        $matrixTypes = NotificationEventMatrix::implementedMachineTypes();
        sort($matrixTypes);

        $this->assertSame($enumValues, $matrixTypes);

        foreach (NotificationType::cases() as $type) {
            foreach (['ar', 'en'] as $locale) {
                $title = Lang::get($type->titleKey(), [], $locale);
                $body = Lang::get($type->bodyKey(), [], $locale);

                $this->assertIsString($title);
                $this->assertIsString($body);
                $this->assertNotSame($type->titleKey(), $title);
                $this->assertNotSame($type->bodyKey(), $body);
                $this->assertStringNotContainsString('messages.', $title);
                $this->assertStringNotContainsString('messages.', $body);
            }

            $this->assertSame(
                $this->placeholderTokens((string) Lang::get($type->bodyKey(), [], 'en')),
                $this->placeholderTokens((string) Lang::get($type->bodyKey(), [], 'ar')),
                'Placeholder parity failed for '.$type->value
            );
        }
    }

    public function test_legacy_license_issued_type_cannot_be_newly_emitted(): void
    {
        $citizen = $this->citizen();

        app(NotificationService::class)->notify(
            $citizen->id,
            NotificationType::ApplicationLicenseIssued,
            ['application_id' => 1, 'application_number' => 'APP-1', 'status' => 'license_issued']
        );

        $this->assertSame(0, Notification::query()->where('user_id', $citizen->id)->count());

        $this->expectException(\InvalidArgumentException::class);
        app(NotificationService::class)->sendLocalizedToUser(
            $citizen->id,
            NotificationType::ApplicationLicenseIssued->titleKey(),
            NotificationType::ApplicationLicenseIssued->bodyKey(),
            [],
            NotificationType::ApplicationLicenseIssued->value,
            ['application_id' => 1]
        );
    }

    public function test_payload_normalize_strips_sensitive_and_unknown_keys(): void
    {
        $normalized = NotificationPayload::normalize(NotificationType::PaymentCompleted, [
            'application_id' => 1,
            'payment_id' => 2,
            'payment_number' => 'PAY-1',
            'amount' => 100,
            'currency' => 'USD',
            'national_id' => '123',
            'card_token' => 'tok_secret',
            'file_path' => '/private/x.pdf',
        ]);

        $this->assertSame([
            'application_id' => 1,
            'payment_id' => 2,
            'payment_number' => 'PAY-1',
            'amount' => 100,
            'currency' => 'USD',
        ], $normalized);
    }

    public function test_silent_application_statuses_match_matrix(): void
    {
        foreach (NotificationEventMatrix::silentApplicationStatuses() as $status) {
            $enum = ApplicationStatus::from($status);
            $this->assertNull(NotificationType::tryFromApplicationStatus($enum));
        }
    }

    public function test_status_notify_fills_application_number_placeholders(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'en',
        ]);

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-REJ-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::AdministrativeReview,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ]);

        app(ApplicationRepository::class)->transitionStatus(
            $application,
            ApplicationStatus::Rejected,
            null,
            'Certification reject path'
        );

        $notification = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', NotificationType::ApplicationRejected->value)
            ->firstOrFail();

        $this->assertStringContainsString($application->application_number, $notification->body);
        $this->assertStringNotContainsString(':application_number', $notification->body);
        $this->assertStringNotContainsString('messages.', $notification->title.$notification->body);
    }

    public function test_already_active_citizen_activation_does_not_spam_notifications(): void
    {
        $admin = User::factory()->dashboardAdmin('admin')->create();
        $citizen = $this->citizen();
        $this->assertTrue((bool) $citizen->is_active);

        app(DashboardCitizenService::class)->activate($admin, $citizen->id);
        app(DashboardCitizenService::class)->activate($admin, $citizen->id);

        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::AccountActivated->value)
                ->count()
        );
    }

    public function test_reactivation_after_deactivate_emits_exactly_one_activated_notification(): void
    {
        $admin = User::factory()->dashboardAdmin('admin')->create();
        $citizen = $this->citizen();

        app(DashboardCitizenService::class)->deactivate($admin, $citizen->id, 'Policy');
        app(DashboardCitizenService::class)->activate($admin, $citizen->id);
        app(DashboardCitizenService::class)->activate($admin, $citizen->id);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::AccountActivated->value)
                ->count()
        );
    }

    public function test_production_after_commit_strategy_is_present(): void
    {
        $source = file_get_contents((new ReflectionClass(NotificationService::class))->getFileName()) ?: '';

        $this->assertStringContainsString('DB::afterCommit($callback)', $source);
        $this->assertStringContainsString('runningUnitTests()', $source);
        $this->assertStringContainsString('transactionLevel()', $source);
    }

    public function test_final_notification_center_routes_and_resource_contract(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);
        $service->sendToUser($citizen->id, 'Title', 'Body', NotificationType::FineCreated->value, ['fine_id' => 1]);

        Sanctum::actingAs($citizen);

        $list = $this->getJson('/api/notifications')->assertOk()->json('data.items.0');
        foreach (['id', 'title', 'body', 'type', 'read_at', 'is_read', 'data', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $list);
        }
        $this->assertArrayNotHasKey('event_key', $list);
        $this->assertArrayNotHasKey('user_id', $list);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->putJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->putJson('/api/notifications/'.$list['id'].'/read')->assertOk();
    }

    public function test_appointment_and_payment_event_keys_remain_semantic(): void
    {
        $this->assertNotSame(
            NotificationEventKey::forAppointment(NotificationType::AppointmentBooked, 9),
            NotificationEventKey::forAppointment(NotificationType::AppointmentCancelled, 9)
        );

        $this->assertNotSame(
            NotificationEventKey::forAppointmentReschedule(9, 1, now()),
            NotificationEventKey::forAppointmentReschedule(9, 2, now()->addHour())
        );

        $this->assertNotSame(
            NotificationEventKey::forPaymentCode(NotificationType::PaymentFailed, 3, 'a'),
            NotificationEventKey::forPaymentCode(NotificationType::PaymentFailed, 3, 'b')
        );
    }

    /**
     * @return list<string>
     */
    private function placeholderTokens(string $text): array
    {
        preg_match_all('/:([a-zA-Z0-9_]+)/', $text, $matches);
        $tokens = $matches[1] ?? [];
        sort($tokens);

        return array_values($tokens);
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'is_active' => true,
        ]);
    }
}
