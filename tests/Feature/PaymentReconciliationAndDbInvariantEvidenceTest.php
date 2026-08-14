<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentGatewayEventStatus;
use App\Enums\PaymentStatus;
use App\Models\Fee;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\PaymentGatewayEvent;
use App\Models\PushDevice;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Payments\Services\StripePaymentGatewayService;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use App\Modules\Payments\Support\Money;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session as CheckoutSession;
use Tests\TestCase;

class PaymentReconciliationAndDbInvariantEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            TestTypesSeeder::class,
            FeesSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_payment_reconciliation_completes_pending_stripe_payment_idempotently(): void
    {
        config([
            'payment.provider' => 'stripe',
            'payment.stripe.currency' => 'usd',
            'payment.stripe.secret_key' => 'sk_test_fake_reconcile',
        ]);

        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $amount = ApplicationFeeCatalog::amountFor('application_fee');

        $payment = Payment::query()->create([
            'payment_number' => 'PAY-REC-'.strtoupper(Str::random(6)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
            'provider' => 'stripe',
            'provider_reference' => 'cs_reconcile_1',
            'active_obligation_key' => Payment::obligationKey($application->id, $fee->id),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $this->mock(StripePaymentGatewayService::class, function ($mock) use ($amount): void {
            $mock->shouldReceive('retrieveCheckoutSession')
                ->atLeast()->once()
                ->andReturn(CheckoutSession::constructFrom([
                    'id' => 'cs_reconcile_1',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => Money::toMinorUnits($amount, 'USD'),
                    'currency' => 'usd',
                    'payment_intent' => 'pi_reconcile_1',
                    'url' => null,
                ]));
        });

        /** @var \App\Modules\Payments\Services\PaymentReconciliationService $reconciliation */
        $reconciliation = app(\App\Modules\Payments\Services\PaymentReconciliationService::class);

        $first = $reconciliation->reconcile($payment->fresh(), null, 'scheduled');
        $this->assertSame('completed', $first['result']);
        $this->assertSame(PaymentStatus::Completed, $first['payment']->status);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::AppointmentPending->value,
        ]);

        $completedCount = Payment::query()
            ->where('application_id', $application->id)
            ->where('status', PaymentStatus::Completed)
            ->count();
        $this->assertSame(1, $completedCount);

        $second = $reconciliation->reconcile($payment->fresh(), null, 'scheduled');
        $this->assertSame('already_completed', $second['result']);
        $this->assertSame(
            1,
            Payment::query()
                ->where('application_id', $application->id)
                ->where('status', PaymentStatus::Completed)
                ->count()
        );

        // Command path: stale pending selector should find nothing after completion.
        $this->artisan('payments:reconcile-pending', [
            '--minutes' => 60,
            '--limit' => 10,
        ])->assertSuccessful();

        $this->assertSame(
            1,
            Payment::query()
                ->where('application_id', $application->id)
                ->where('status', PaymentStatus::Completed)
                ->count()
        );
    }

    public function test_active_obligation_key_unique_constraint(): void
    {
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $key = Payment::obligationKey($application->id, $fee->id);

        Payment::query()->create([
            'payment_number' => 'PAY-AOK-1',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => '50.00',
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
            'provider' => 'mock',
            'active_obligation_key' => $key,
        ]);

        $this->expectException(QueryException::class);
        Payment::query()->create([
            'payment_number' => 'PAY-AOK-2',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => '50.00',
            'currency' => 'USD',
            'status' => PaymentStatus::UnderVerification,
            'provider' => 'mock',
            'active_obligation_key' => $key,
        ]);
    }

    public function test_payment_gateway_event_provider_event_id_unique_constraint(): void
    {
        PaymentGatewayEvent::query()->create([
            'provider' => 'stripe',
            'event_id' => 'evt_unique_hardening',
            'event_type' => 'checkout.session.completed',
            'processing_status' => PaymentGatewayEventStatus::Processed,
            'payload_hash' => hash('sha256', 'a'),
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        PaymentGatewayEvent::query()->create([
            'provider' => 'stripe',
            'event_id' => 'evt_unique_hardening',
            'event_type' => 'checkout.session.completed',
            'processing_status' => PaymentGatewayEventStatus::Processed,
            'payload_hash' => hash('sha256', 'b'),
            'received_at' => now(),
            'processed_at' => now(),
        ]);
    }

    public function test_license_number_unique_constraint(): void
    {
        [$citizen, $application] = $this->issuedApplicationShell();
        $licenseTypeId = (int) $application->license_type_id;

        License::query()->create([
            'license_number' => 'LIC-DUP-HARDEN',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'application_id' => $application->id,
            'status' => \App\Enums\LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(10)->toDateString(),
            'verification_token' => Str::random(48),
        ]);

        $application2 = LicenseApplication::query()->create([
            'application_number' => 'APP-DUP2-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $application->service_type_id,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now(),
            'issued_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        License::query()->create([
            'license_number' => 'LIC-DUP-HARDEN',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'application_id' => $application2->id,
            'status' => \App\Enums\LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(10)->toDateString(),
            'verification_token' => Str::random(48),
        ]);
    }

    public function test_license_verification_token_unique_constraint(): void
    {
        [$citizen, $application] = $this->issuedApplicationShell();
        $token = 'token-harden-'.Str::random(24);

        License::query()->create([
            'license_number' => 'LIC-TOK-1-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application->id,
            'status' => \App\Enums\LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(10)->toDateString(),
            'verification_token' => $token,
        ]);

        $application2 = LicenseApplication::query()->create([
            'application_number' => 'APP-TOK2-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $application->license_type_id,
            'service_type_id' => $application->service_type_id,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now(),
            'issued_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        License::query()->create([
            'license_number' => 'LIC-TOK-2-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application2->id,
            'status' => \App\Enums\LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(10)->toDateString(),
            'verification_token' => $token,
        ]);
    }

    public function test_push_devices_user_id_device_id_unique_constraint(): void
    {
        $user = User::factory()->create();

        PushDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-harden-1',
            'platform' => 'android',
            'token' => 'token-a-plain',
            'token_hash' => hash('sha256', 'token-a'),
            'last_registered_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        PushDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-harden-1',
            'platform' => 'android',
            'token' => 'token-b-plain',
            'token_hash' => hash('sha256', 'token-b'),
            'last_registered_at' => now(),
        ]);
    }

    /**
     * @return array{0: User, 1: LicenseApplication, 2: Fee}
     */
    private function citizenApplicationFee(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $fee = Fee::query()->where('code', 'application_fee')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-REC-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::PaymentPending,
            'submitted_at' => now(),
        ]);

        return [$citizen, $application, $fee];
    }

    /**
     * @return array{0: User, 1: LicenseApplication}
     */
    private function issuedApplicationShell(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-INV-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now(),
            'issued_at' => now(),
        ]);

        return [$citizen, $application];
    }
}
