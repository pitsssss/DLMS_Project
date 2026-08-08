<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserType;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Dashboard\Services\DashboardCitizenService;
use App\Modules\Fines\Services\FineService;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Payments\Services\PaymentLifecycleService;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipientNotificationLocaleTest extends TestCase
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
            TestTypesSeeder::class,
            FeesSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_ar_recipient_stores_arabic_title_and_body(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);

        app(NotificationService::class)->sendLocalizedToUser(
            $citizen->id,
            'messages.notifications.payment_required_title',
            'messages.notifications.payment_required_body',
            [],
            'application.payment_pending'
        );

        $notification = Notification::query()->where('user_id', $citizen->id)->firstOrFail();

        $this->assertSame(Lang::get('messages.notifications.payment_required_title', [], 'ar'), $notification->title);
        $this->assertSame(Lang::get('messages.notifications.payment_required_body', [], 'ar'), $notification->body);
        $this->assertStringNotContainsString('messages.', $notification->title.$notification->body);
    }

    public function test_en_recipient_stores_english_title_and_body(): void
    {
        $citizen = $this->citizen(['language' => 'en']);

        app(NotificationService::class)->sendLocalizedToUser(
            $citizen->id,
            'messages.notifications.payment_required_title',
            'messages.notifications.payment_required_body',
            [],
            'application.payment_pending'
        );

        $notification = Notification::query()->where('user_id', $citizen->id)->firstOrFail();

        $this->assertSame(Lang::get('messages.notifications.payment_required_title', [], 'en'), $notification->title);
        $this->assertSame(Lang::get('messages.notifications.payment_required_body', [], 'en'), $notification->body);
        $this->assertStringNotContainsString('messages.', $notification->title.$notification->body);
    }

    public function test_request_locale_does_not_override_recipient_language(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);

        Sanctum::actingAs($citizen);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        app()->setLocale('en');
        $localeBefore = app()->getLocale();

        app(NotificationService::class)->sendLocalizedToUser(
            $citizen->id,
            'messages.notifications.approved_title',
            'messages.notifications.approved_body',
            [],
            'application.approved'
        );

        $notification = Notification::query()->where('user_id', $citizen->id)->latest('id')->firstOrFail();

        $this->assertSame(Lang::get('messages.notifications.approved_title', [], 'ar'), $notification->title);
        $this->assertSame(Lang::get('messages.notifications.approved_body', [], 'ar'), $notification->body);
        $this->assertSame($localeBefore, app()->getLocale());
    }

    public function test_dashboard_created_notification_respects_recipient_language(): void
    {
        $admin = User::factory()->dashboardAdmin('admin')->create();
        $citizen = $this->citizen(['language' => 'en', 'is_active' => true]);
        app()->setLocale('ar');
        $localeBefore = app()->getLocale();

        app(DashboardCitizenService::class)->deactivate($admin, $citizen->id, 'Policy violation XYZ');

        $notification = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'account.deactivated')
            ->firstOrFail();

        $this->assertSame(Lang::get('messages.notifications.account_deactivated_title', [], 'en'), $notification->title);
        $this->assertSame(
            Lang::get('messages.notifications.account_deactivated_body', ['reason' => 'Policy violation XYZ'], 'en'),
            $notification->body
        );
        $this->assertStringContainsString('Policy violation XYZ', $notification->body);
        $this->assertSame($localeBefore, app()->getLocale());
        $this->assertStringNotContainsString('messages.', $notification->title.$notification->body);
    }

    public function test_payment_lifecycle_notification_respects_recipient_language(): void
    {
        $citizen = $this->citizen(['language' => 'en']);
        [$application, $payment] = $this->pendingPaymentFor($citizen);
        app()->setLocale('ar');
        $localeBefore = app()->getLocale();

        app(PaymentLifecycleService::class)->completeVerifiedPayment(
            $payment->id,
            null,
            [],
            null,
            'stripe_webhook'
        );

        $notification = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'payment.completed')
            ->firstOrFail();

        $expectedTitle = Lang::get('messages.notifications.payment_completed_title', [], 'en');
        $this->assertSame($expectedTitle, $notification->title);
        $this->assertStringContainsString($payment->payment_number, $notification->body);
        $this->assertStringContainsString($application->application_number, $notification->body);
        $this->assertStringNotContainsString(Lang::get('messages.notifications.payment_completed_title', [], 'ar'), $notification->title);
        $this->assertSame($localeBefore, app()->getLocale());
        $this->assertStringNotContainsString('messages.', $notification->title.$notification->body);
    }

    public function test_placeholders_are_substituted_and_free_text_is_preserved(): void
    {
        $citizen = $this->citizen(['language' => 'en']);
        $admin = User::factory()->dashboardAdmin('admin')->create();
        $freeTextReason = 'Speeding on highway 42 — raw text';

        app(FineService::class)->create($admin, $citizen->id, 12500.5, $freeTextReason);

        $notification = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'fine.created')
            ->firstOrFail();

        $this->assertSame(Lang::get('messages.notifications.fine_issued_title', [], 'en'), $notification->title);
        $this->assertStringContainsString('12500.5', $notification->body);
        $this->assertStringContainsString($freeTextReason, $notification->body);
        $this->assertStringNotContainsString(':amount', $notification->body);
        $this->assertStringNotContainsString(':reason', $notification->body);
        $this->assertStringNotContainsString('messages.', $notification->title.$notification->body);
    }

    public function test_unsupported_or_null_language_falls_back_to_arabic(): void
    {
        $emptyLangCitizen = $this->citizen(['language' => '']);
        $unsupportedCitizen = $this->citizen(['language' => 'de']);

        foreach ([$emptyLangCitizen, $unsupportedCitizen] as $citizen) {
            app(NotificationService::class)->sendLocalizedToUser(
                $citizen->id,
                'messages.notifications.retest_title',
                'messages.notifications.retest_body',
                [],
                'application.waiting_retest'
            );

            $notification = Notification::query()
                ->where('user_id', $citizen->id)
                ->latest('id')
                ->firstOrFail();

            $this->assertSame(Lang::get('messages.notifications.retest_title', [], 'ar'), $notification->title);
            $this->assertSame(Lang::get('messages.notifications.retest_body', [], 'ar'), $notification->body);
        }
    }

    public function test_historical_notification_is_returned_unchanged_after_language_change(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);
        $historicalTitle = 'عنوان تاريخي ثابت';
        $historicalBody = 'نص إشعار قديم لا يعاد ترجمته';

        $notification = app(NotificationService::class)->sendToUser(
            $citizen->id,
            $historicalTitle,
            $historicalBody,
            'legacy.test',
            ['legacy' => true]
        );

        $citizen->update(['language' => 'en']);

        Sanctum::actingAs($citizen->fresh());

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $notification->id)
            ->assertJsonPath('data.items.0.title', $historicalTitle)
            ->assertJsonPath('data.items.0.body', $historicalBody);

        $this->assertSame($historicalTitle, $notification->fresh()->title);
        $this->assertSame($historicalBody, $notification->fresh()->body);
    }

    public function test_application_status_notification_uses_recipient_language(): void
    {
        $citizen = $this->citizen(['language' => 'en']);
        $application = $this->applicationFor($citizen, ApplicationStatus::Draft);
        app()->setLocale('ar');

        app(\App\Modules\Applications\Repositories\ApplicationRepository::class)->transitionStatus(
            $application,
            ApplicationStatus::PaymentPending,
            null,
            'dashboard transition'
        );

        $notification = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'application.payment_pending')
            ->firstOrFail();

        $this->assertSame(Lang::get('messages.notifications.payment_required_title', [], 'en'), $notification->title);
        $this->assertSame(Lang::get('messages.notifications.payment_required_body', [], 'en'), $notification->body);
    }

    public function test_send_localized_does_not_mutate_app_locale(): void
    {
        $citizen = $this->citizen(['language' => 'en']);
        app()->setLocale('ar');

        app(NotificationService::class)->sendLocalizedToUser(
            $citizen->id,
            'messages.notifications.license_issued_title',
            'messages.notifications.license_issued_body'
        );

        $this->assertSame('ar', app()->getLocale());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function citizen(array $overrides = []): User
    {
        return User::factory()->withApprovedProfile()->create(array_merge([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ], $overrides));
    }

    private function applicationFor(User $citizen, ApplicationStatus $status): LicenseApplication
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return LicenseApplication::query()->create([
            'application_number' => 'APP-RNL-'.strtoupper(Str::random(6)),
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

    /**
     * @return array{0: LicenseApplication, 1: Payment}
     */
    private function pendingPaymentFor(User $citizen): array
    {
        $application = $this->applicationFor($citizen, ApplicationStatus::PaymentPending);
        $fee = Fee::query()
            ->where('is_active', true)
            ->where(function ($q) use ($application): void {
                $q->where(function ($scoped) use ($application): void {
                    $scoped->where('license_type_id', $application->license_type_id)
                        ->where('service_type_id', $application->service_type_id);
                })->orWhere(function ($scoped) use ($application): void {
                    $scoped->whereNull('license_type_id')
                        ->where('service_type_id', $application->service_type_id);
                });
            })
            ->orderByRaw('license_type_id IS NULL')
            ->firstOrFail();

        $key = Payment::obligationKey($application->id, $fee->id);

        $payment = Payment::query()->create([
            'payment_number' => 'PAY-RNL-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fine_id' => null,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Pending,
            'provider' => 'stripe',
            'provider_reference' => 'cs_test_rnl',
            'paid_at' => null,
            'failed_at' => null,
            'active_obligation_key' => $key,
            'settled_obligation_key' => null,
            'metadata' => [],
        ]);

        return [$application, $payment];
    }
}
