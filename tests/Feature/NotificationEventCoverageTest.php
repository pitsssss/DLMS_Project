<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentFailureCode;
use App\Enums\PaymentStatus;
use App\Enums\UserType;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Notifications\Support\NotificationEventMatrix;
use App\Modules\Payments\Services\PaymentLifecycleService;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationEventCoverageTest extends TestCase
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
    }

    public function test_matrix_machine_types_are_registered_with_translations_and_payloads(): void
    {
        foreach (NotificationEventMatrix::implementedMachineTypes() as $typeValue) {
            $type = NotificationType::tryFrom($typeValue);
            $this->assertNotNull($type, "Missing NotificationType for {$typeValue}");

            if ($type->isLegacyEmissionSuppressed()) {
                continue;
            }

            $this->assertTrue(Lang::has($type->titleKey(), 'ar'), "Missing AR title for {$typeValue}");
            $this->assertTrue(Lang::has($type->titleKey(), 'en'), "Missing EN title for {$typeValue}");
            $this->assertTrue(Lang::has($type->bodyKey(), 'ar'), "Missing AR body for {$typeValue}");
            $this->assertTrue(Lang::has($type->bodyKey(), 'en'), "Missing EN body for {$typeValue}");
            $this->assertIsArray($type->allowedDataKeys());
        }

        $deferred = collect(NotificationEventMatrix::entries())
            ->firstWhere('type', 'appointment.reminder');
        $this->assertNotNull($deferred);
        $this->assertSame('deferred', $deferred['coverage']);
        $this->assertSame('deferred', $deferred['phase']);
        $this->assertContains('appointment.booked', NotificationEventMatrix::n2Types());
        $this->assertContains('payment.failed', NotificationEventMatrix::n2Types());
    }

    public function test_application_rejected_and_cancelled_are_wired_in_status_map(): void
    {
        $citizen = $this->citizen(['language' => 'en']);
        $application = $this->applicationFor($citizen, ApplicationStatus::Approved);
        $repo = app(ApplicationRepository::class);

        $repo->transitionStatus($application, ApplicationStatus::Rejected, null, 'n2 reject');
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::ApplicationRejected->value)
                ->count()
        );

        $application2 = $this->applicationFor($citizen, ApplicationStatus::Approved);
        $repo->transitionStatus($application2, ApplicationStatus::Cancelled, null, 'n2 cancel');
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::ApplicationCancelled->value)
                ->count()
        );

        $rejected = Notification::query()->where('type', NotificationType::ApplicationRejected->value)->firstOrFail();
        $this->assertSame(Lang::get('messages.notifications.application_rejected_title', [], 'en'), $rejected->title);
        $this->assertStringNotContainsString('messages.', $rejected->title.$rejected->body);
    }

    public function test_silent_application_statuses_do_not_notify(): void
    {
        $citizen = $this->citizen();
        $application = $this->applicationFor($citizen, ApplicationStatus::PaymentPending);
        $before = Notification::query()->where('user_id', $citizen->id)->count();

        app(ApplicationRepository::class)->transitionStatus(
            $application,
            ApplicationStatus::PaymentCompleted,
            null,
            'silent'
        );

        $this->assertSame($before, Notification::query()->where('user_id', $citizen->id)->count());
    }

    public function test_payment_failed_and_under_verification_notify_once_per_code(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);
        [, $payment] = $this->pendingPaymentFor($citizen);
        $lifecycle = app(PaymentLifecycleService::class);

        $lifecycle->markFailed($payment->id, PaymentFailureCode::SessionExpired);
        $lifecycle->markFailed($payment->id, PaymentFailureCode::SessionExpired);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::PaymentFailed->value)
                ->count()
        );

        $failed = Notification::query()->where('type', NotificationType::PaymentFailed->value)->firstOrFail();
        $this->assertSame(Lang::get('messages.notifications.payment_failed_title', [], 'ar'), $failed->title);
        $this->assertSame($payment->id, $failed->data['payment_id']);

        $payment->refresh();
        $payment->update(['status' => PaymentStatus::Pending, 'failure_code' => null]);

        $lifecycle->markUnderVerification($payment->id, PaymentFailureCode::AmountMismatch);
        $lifecycle->markUnderVerification($payment->id, PaymentFailureCode::AmountMismatch);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::PaymentUnderVerification->value)
                ->count()
        );
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
            'application_number' => 'APP-N2-'.strtoupper(Str::random(6)),
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
            'payment_number' => 'PAY-N2-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fine_id' => null,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Pending,
            'provider' => 'stripe',
            'provider_reference' => 'cs_test_n2',
            'paid_at' => null,
            'failed_at' => null,
            'active_obligation_key' => $key,
            'settled_obligation_key' => null,
            'metadata' => [],
        ]);

        return [$application, $payment];
    }
}
