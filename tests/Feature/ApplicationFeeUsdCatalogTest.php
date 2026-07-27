<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Stripe\Checkout\Session as CheckoutSession;
use Tests\Concerns\InteractsWithDashboard;
use Tests\Support\StripeWebhookTestSigner;
use Tests\TestCase;

class ApplicationFeeUsdCatalogTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    private const WebhookSecret = 'whsec_test_signing_secret_for_phpunit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->seed([
            RolesSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            TestTypesSeeder::class,
            FeesSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);

        config([
            'payment.provider' => 'mock',
        ]);
    }

    private function withStripeConfig(): void
    {
        config([
            'payment.provider' => 'stripe',
            'payment.stripe.secret_key' => 'sk_test_fake_key_for_unit_tests',
            'payment.stripe.webhook_secret' => self::WebhookSecret,
            'payment.stripe.currency' => 'usd',
            'payment.stripe.success_url' => 'http://localhost:3000/payment/success',
            'payment.stripe.cancel_url' => 'http://localhost:3000/payment/cancel',
            'payment.stripe.publishable_key' => 'pk_test_fake',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_application_fee_catalog_uses_usd_amounts(): void
    {
        foreach (ApplicationFeeCatalog::APPLICATION_PAYABLE_CODES as $code) {
            $fee = Fee::query()->where('code', $code)->where('is_active', true)->first();
            $this->assertNotNull($fee, "Missing fee for {$code}");
            $this->assertSame(ApplicationFeeCatalog::CURRENCY, $fee->currency);
            $this->assertSame(
                ApplicationFeeCatalog::amountFor($code),
                Money::format((string) $fee->amount)
            );
        }
    }

    public function test_usd_minor_unit_conversion_is_exact(): void
    {
        $this->assertSame(1000, Money::toMinorUnits('10.00', 'USD'));
        $this->assertSame(1025, Money::toMinorUnits('10.25', 'USD'));
        $this->assertSame(50, Money::toMinorUnits('0.50', 'USD'));
        $this->assertSame(101, Money::toMinorUnits('1.01', 'USD'));
        $this->assertSame(
            Money::toMinorUnits(ApplicationFeeCatalog::amountFor('application_fee'), 'USD'),
            Money::toMinorUnits('50.00', 'USD')
        );
    }

    public function test_new_payment_snapshots_fee_amount_and_usd_currency(): void
    {
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        Sanctum::actingAs($citizen);

        $this->postJson("/api/applications/{$application->id}/payments", [])->assertOk();

        $this->assertDatabaseHas('payments', [
            'application_id' => $application->id,
            'amount' => ApplicationFeeCatalog::amountFor('application_fee'),
            'currency' => 'USD',
        ]);
    }

    public function test_client_cannot_override_amount_or_currency(): void
    {
        [$citizen, $application] = $this->citizenApplicationFee();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [
            'amount' => '1.00',
            'currency' => 'EUR',
        ])->assertOk()->json('data.id');

        $payment = Payment::query()->findOrFail($paymentId);
        $this->assertSame(ApplicationFeeCatalog::amountFor('application_fee'), Money::format((string) $payment->amount));
        $this->assertSame('USD', $payment->currency);
    }

    public function test_non_usd_fee_is_rejected_before_stripe_is_called(): void
    {
        $this->withStripeConfig();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        Fee::query()->whereKey($fee->id)->update(['currency' => 'EUR']);
        Sanctum::actingAs($citizen);

        $this->mock(StripePaymentGatewayService::class, function ($mock): void {
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->postJson("/api/applications/{$application->id}/payments", [])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.payments.provider_currency_unsupported'));
    }

    public function test_usd_stripe_webhook_completes_matching_payment(): void
    {
        $this->withStripeConfig();
        $this->mockStripeGateway();
        [$citizen, $application] = $this->citizenApplicationFee();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');
        $minor = Money::toMinorUnits(ApplicationFeeCatalog::amountFor('application_fee'), 'USD');

        $payload = json_encode([
            'id' => 'evt_usd_ok',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => $minor,
                    'currency' => 'usd',
                    'payment_intent' => 'pi_webhook',
                    'metadata' => ['payment_id' => (string) $paymentId],
                ],
            ],
        ]);

        $header = StripeWebhookTestSigner::sign($payload, self::WebhookSecret);
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => PaymentStatus::Completed->value,
            'currency' => 'USD',
        ]);
    }

    public function test_currency_mismatch_moves_payment_to_under_verification(): void
    {
        $this->withStripeConfig();
        $this->mockStripeGateway();
        [$citizen, $application] = $this->citizenApplicationFee();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');

        $payload = json_encode([
            'id' => 'evt_currency_bad',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 5000,
                    'currency' => 'eur',
                    'metadata' => ['payment_id' => (string) $paymentId],
                ],
            ],
        ]);

        $header = StripeWebhookTestSigner::sign($payload, self::WebhookSecret);
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => PaymentStatus::UnderVerification->value,
        ]);
        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::PaymentPending->value,
        ]);
    }

    public function test_amount_mismatch_moves_payment_to_under_verification(): void
    {
        $this->withStripeConfig();
        $this->mockStripeGateway();
        [$citizen, $application] = $this->citizenApplicationFee();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');

        $payload = json_encode([
            'id' => 'evt_amount_bad',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 999,
                    'currency' => 'usd',
                    'metadata' => ['payment_id' => (string) $paymentId],
                ],
            ],
        ]);

        $header = StripeWebhookTestSigner::sign($payload, self::WebhookSecret);
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => PaymentStatus::UnderVerification->value,
        ]);
    }

    public function test_dashboard_statistics_do_not_mix_currencies(): void
    {
        $manager = User::factory()->dashboardEmployee('payment_employee')->create();
        Sanctum::actingAs($manager);

        [$citizen, $application, $fee] = $this->citizenApplicationFee();

        Payment::query()->create([
            'payment_number' => 'PAY-HIST-SYP',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => '1000.00',
            'currency' => 'SYP',
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'paid_at' => now(),
            'settled_obligation_key' => null,
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-NEW-USD',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => '50.00',
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'provider' => 'stripe',
            'paid_at' => now(),
            'settled_obligation_key' => null,
        ]);

        $data = $this->getJson('/api/dashboard/payments/stats')->assertOk()->json('data');
        $this->assertNull($data['completed_amount']);
        $this->assertNull($data['currency']);
        $this->assertSame('1000.00', $data['completed_amount_by_currency']['SYP']);
        $this->assertSame('50.00', $data['completed_amount_by_currency']['USD']);
    }

    public function test_dashboard_options_expose_usd_label(): void
    {
        $manager = User::factory()->dashboardEmployee('payment_employee')->create();
        Sanctum::actingAs($manager);

        $currencies = $this->getJson('/api/dashboard/payments/options')->assertOk()->json('data.currencies');
        $usd = collect($currencies)->firstWhere('value', 'USD');
        $this->assertNotNull($usd);
        $this->assertSame('دولار أمريكي', $usd['label']);
    }

    public function test_dashboard_list_and_due_fees_return_usd(): void
    {
        $manager = User::factory()->dashboardEmployee('payment_employee')->create();
        Sanctum::actingAs($manager);

        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        Payment::query()->create([
            'payment_number' => 'PAY-LIST-USD',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => ApplicationFeeCatalog::amountFor('application_fee'),
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
            'provider' => 'mock',
            'active_obligation_key' => Payment::obligationKey($application->id, $fee->id),
        ]);

        $item = $this->getJson('/api/dashboard/payments')->assertOk()->json('data.items.0');
        $this->assertSame('USD', $item['currency']);

        $due = $this->getJson('/api/dashboard/payments/due-fees')->assertOk()->json('data.items.0');
        $this->assertSame('USD', $due['fee']['currency']);
    }

    public function test_completed_historical_syp_payment_snapshot_is_preserved(): void
    {
        [$citizen, $application, $fee] = $this->citizenApplicationFee();

        $historical = Payment::query()->create([
            'payment_number' => 'PAY-HIST-KEEP',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => '15000.00',
            'currency' => 'SYP',
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'paid_at' => now()->subYear(),
            'settled_obligation_key' => Payment::obligationKey($application->id, $fee->id),
        ]);

        $this->artisan('db:seed', ['--class' => FeesSeeder::class]);

        $historical->refresh();
        $this->assertSame('15000.00', Money::format((string) $historical->amount));
        $this->assertSame('SYP', $historical->currency);
    }

    /**
     * @return array{0: User, 1: LicenseApplication, 2: Fee}
     */
    private function citizenApplicationFee(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-USD-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::PaymentPending,
            'submitted_at' => now(),
        ]);

        $fee = Fee::query()
            ->where('license_type_id', $licenseType->id)
            ->where('service_type_id', $serviceType->id)
            ->where('code', 'application_fee')
            ->firstOrFail();

        return [$citizen, $application, $fee];
    }

    private function mockStripeGateway(): void
    {
        $this->mock(StripePaymentGatewayService::class, function ($mock): void {
            $mock->shouldReceive('createCheckoutSession')
                ->andReturnUsing(function ($payment, $fee, $user, $app) {
                    $minor = Money::toMinorUnits((string) $payment->amount, (string) $payment->currency);
                    $session = CheckoutSession::constructFrom([
                        'id' => 'cs_test_'.$payment->id,
                        'url' => 'https://checkout.stripe.test/pay/'.$payment->id,
                        'payment_status' => 'unpaid',
                        'status' => 'open',
                        'amount_total' => $minor,
                        'currency' => 'usd',
                        'metadata' => [
                            'payment_id' => (string) $payment->id,
                            'application_id' => (string) $app->id,
                        ],
                    ]);

                    return [
                        'session_id' => $session->id,
                        'url' => (string) $session->url,
                        'session' => $session,
                    ];
                });
        });
    }
}
