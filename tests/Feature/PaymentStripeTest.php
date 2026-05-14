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
use Tests\Support\StripeWebhookTestSigner;
use Tests\TestCase;

class PaymentStripeTest extends TestCase
{
    use RefreshDatabase;

    private const WebhookSecret = 'whsec_test_signing_secret_for_phpunit';

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

        config([
            'payment.provider' => 'stripe',
            'payment.stripe.secret_key' => 'sk_test_fake_key_for_unit_tests',
            'payment.stripe.webhook_secret' => self::WebhookSecret,
            'payment.stripe.currency' => 'usd',
            'payment.stripe.success_url' => 'http://localhost:3000/payment/success?session_id={CHECKOUT_SESSION_ID}',
            'payment.stripe.cancel_url' => 'http://localhost:3000/payment/cancel',
            'payment.stripe.publishable_key' => 'pk_test_fake',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function stripeCitizenApplication(): array
    {
        $citizen = User::factory()->create([
            'profile_completed' => true,
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        Fee::query()
            ->where('license_type_id', $licenseType->id)
            ->where('service_type_id', $serviceType->id)
            ->where('code', 'application_fee')
            ->update(['amount' => 10.00, 'currency' => 'USD']);

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-ST-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::PaymentPending,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ]);

        return [$citizen, $application];
    }

    private function mockStripeGatewayForCreate(): void
    {
        $this->mock(StripePaymentGatewayService::class, function ($mock): void {
            $mock->shouldReceive('createCheckoutSession')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function ($payment, $fee, $user, $app) {
                    $session = CheckoutSession::constructFrom([
                        'id' => 'cs_test_'.$payment->id,
                        'object' => 'checkout.session',
                        'url' => 'https://checkout.stripe.test/pay/'.$payment->id,
                        'payment_status' => 'unpaid',
                        'status' => 'open',
                        'amount_total' => 1000,
                        'currency' => 'usd',
                        'payment_intent' => 'pi_test_1',
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

            $mock->shouldReceive('retrieveCheckoutSession')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (string $sessionId) {
                    $id = str_replace('cs_test_', '', $sessionId);

                    return CheckoutSession::constructFrom([
                        'id' => $sessionId,
                        'object' => 'checkout.session',
                        'url' => 'https://checkout.stripe.test/pay/'.$id,
                        'payment_status' => 'paid',
                        'status' => 'complete',
                        'amount_total' => 1000,
                        'currency' => 'usd',
                        'payment_intent' => 'pi_test_paid',
                        'metadata' => [
                            'payment_id' => $id,
                        ],
                    ]);
                });
        });
    }

    public function test_stripe_create_payment_returns_checkout_url_and_publishable_key(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizen, $application] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizen);

        $response = $this->postJson("/api/applications/{$application->id}/payments", []);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.provider', 'stripe')
            ->assertJsonPath('data.publishable_key', 'pk_test_fake');

        $checkoutUrl = (string) $response->json('data.checkout_url');
        $this->assertStringContainsString('checkout.stripe.test', $checkoutUrl);
    }

    public function test_stripe_manual_confirm_is_disabled(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizen, $application] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');

        $this->postJson("/api/applications/{$application->id}/payments/{$paymentId}/confirm", [])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Manual confirmation is disabled for Stripe payments.');
    }

    public function test_payment_status_endpoint_completes_when_stripe_reports_paid(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizen, $application] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');

        $this->getJson("/api/applications/{$application->id}/payments/{$paymentId}/status")
            ->assertOk()
            ->assertJsonPath('data.payment.status', PaymentStatus::Completed->value)
            ->assertJsonPath('data.application.status', ApplicationStatus::AppointmentPending->value);
    }

    public function test_webhook_checkout_session_completed_marks_payment_completed(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizen, $application] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');

        $payload = json_encode([
            'id' => 'evt_test_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 1000,
                    'currency' => 'usd',
                    'payment_intent' => 'pi_webhook',
                    'metadata' => [
                        'payment_id' => (string) $paymentId,
                    ],
                ],
            ],
        ]);

        $this->assertIsString($payload);

        $header = StripeWebhookTestSigner::sign($payload, self::WebhookSecret);

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => PaymentStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::AppointmentPending->value,
        ]);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizen, $application] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');

        $payload = json_encode([
            'id' => 'evt_dup',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 1000,
                    'currency' => 'usd',
                    'metadata' => ['payment_id' => (string) $paymentId],
                ],
            ],
        ]);

        $header = StripeWebhookTestSigner::sign($payload, self::WebhookSecret);

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertEquals(1, Payment::query()->where('id', $paymentId)->where('status', PaymentStatus::Completed)->count());
    }

    public function test_expired_webhook_marks_payment_failed_and_keeps_application_payment_pending(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizen, $application] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');

        $payload = json_encode([
            'id' => 'evt_exp',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'status' => 'expired',
                    'metadata' => ['payment_id' => (string) $paymentId],
                ],
            ],
        ]);

        $header = StripeWebhookTestSigner::sign($payload, self::WebhookSecret);

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => PaymentStatus::Failed->value,
        ]);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::PaymentPending->value,
        ]);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad',
        ], '{}')->assertStatus(400);
    }

    public function test_cannot_create_payment_when_completed_row_exists_for_fee(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizen, $application] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizen);

        $fee = Fee::query()
            ->where('license_type_id', $application->license_type_id)
            ->where('service_type_id', $application->service_type_id)
            ->where('code', 'application_fee')
            ->firstOrFail();

        Payment::query()->create([
            'payment_number' => 'PAY-TEST-COMPLETE-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fine_id' => null,
            'fee_id' => $fee->id,
            'payable_type' => null,
            'payable_id' => null,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Completed,
            'provider' => 'stripe',
            'provider_reference' => 'cs_prior',
            'paid_at' => now(),
            'metadata' => [],
        ]);

        $this->postJson("/api/applications/{$application->id}/payments", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Payment already completed.');
    }

    public function test_cannot_create_second_stripe_payment_after_successful_flow(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizen, $application] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.payment.id');

        $this->getJson("/api/applications/{$application->id}/payments/{$paymentId}/status")->assertOk();

        $this->postJson("/api/applications/{$application->id}/payments", [])
            ->assertStatus(422);
    }

    public function test_api_routes_file_does_not_double_prefix_api_path(): void
    {
        $contents = file_get_contents(base_path('routes/api.php'));
        $this->assertIsString($contents);
        $this->assertStringNotContainsString("'/api/", $contents);
        $this->assertStringNotContainsString('"/api/', $contents);
    }

    public function test_citizen_cannot_view_another_citizens_payment_status(): void
    {
        $this->mockStripeGatewayForCreate();
        [$citizenA, $applicationA] = $this->stripeCitizenApplication();
        Sanctum::actingAs($citizenA);
        $paymentId = (int) $this->postJson("/api/applications/{$applicationA->id}/payments", [])->json('data.payment.id');

        $citizenB = User::factory()->create([
            'profile_completed' => true,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($citizenB);

        $this->getJson("/api/applications/{$applicationA->id}/payments/{$paymentId}/status")
            ->assertStatus(404);
    }
}
