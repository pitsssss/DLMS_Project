<?php

namespace Tests\Feature;

use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Models\Fine;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payments\Services\StripePaymentGatewayService;
use App\Modules\Payments\Support\Money;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Stripe\Checkout\Session as CheckoutSession;
use Tests\Support\StripeWebhookTestSigner;
use Tests\TestCase;

class FinePaymentStripeTest extends TestCase
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
            'payment.fine_currency' => 'USD',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function unpaidFineCitizen(float $amount = 25.00): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => $amount,
            'currency' => 'USD',
            'reason' => 'Stripe fine',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        return [$citizen, $fine];
    }

    private function mockFineStripeGateway(): void
    {
        $this->mock(StripePaymentGatewayService::class, function ($mock): void {
            $mock->shouldReceive('createFineCheckoutSession')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function ($payment, $fine, $user) {
                    $minor = Money::toMinorUnits((string) $payment->amount, (string) $payment->currency);
                    $session = CheckoutSession::constructFrom([
                        'id' => 'cs_fine_'.$payment->id,
                        'object' => 'checkout.session',
                        'url' => 'https://checkout.stripe.test/fine/'.$payment->id,
                        'payment_status' => 'unpaid',
                        'status' => 'open',
                        'amount_total' => $minor,
                        'currency' => 'usd',
                        'payment_intent' => null,
                        'metadata' => [
                            'payment_id' => (string) $payment->id,
                            'payment_type' => 'fine',
                            'fine_id' => (string) $fine->id,
                        ],
                    ]);

                    return [
                        'session_id' => $session->id,
                        'url' => $session->url,
                        'session' => $session,
                    ];
                });

            $mock->shouldReceive('retrieveCheckoutSession')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (string $sessionId) {
                    $id = (int) str_replace('cs_fine_', '', $sessionId);
                    $payment = Payment::query()->findOrFail($id);
                    $minor = Money::toMinorUnits((string) $payment->amount, (string) $payment->currency);

                    return CheckoutSession::constructFrom([
                        'id' => $sessionId,
                        'object' => 'checkout.session',
                        'url' => 'https://checkout.stripe.test/fine/'.$id,
                        'payment_status' => 'paid',
                        'status' => 'complete',
                        'amount_total' => $minor,
                        'currency' => 'usd',
                        'payment_intent' => 'pi_status',
                        'metadata' => [
                            'payment_id' => (string) $payment->id,
                            'payment_type' => 'fine',
                            'fine_id' => (string) $payment->fine_id,
                        ],
                    ]);
                });
        });
    }

    public function test_stripe_create_returns_checkout_url(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen();
        Sanctum::actingAs($citizen);

        $this->postJson("/api/fines/{$fine->id}/payments", [])
            ->assertOk()
            ->assertJsonPath('data.provider', 'stripe')
            ->assertJsonPath('data.payment.currency', 'USD')
            ->assertJsonPath('data.payment.status', PaymentStatus::Pending->value);

        $url = $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.checkout_url');
        $this->assertStringContainsString('checkout.stripe.test/fine/', (string) $url);
    }

    public function test_does_not_reuse_pending_mock_payment_when_configured_provider_is_stripe(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen(40.00);
        Sanctum::actingAs($citizen);

        $mockPending = Payment::query()->create([
            'payment_number' => 'PAY-MOCK-PENDING-'.uniqid(),
            'user_id' => $citizen->id,
            'application_id' => null,
            'fine_id' => $fine->id,
            'fee_id' => null,
            'payable_type' => null,
            'payable_id' => null,
            'amount' => '40.00',
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
            'provider' => 'mock',
            'provider_reference' => null,
            'paid_at' => null,
            'metadata' => ['source' => 'cross_provider_regression'],
            'active_obligation_key' => Payment::fineObligationKey($fine->id),
            'settled_obligation_key' => null,
        ]);

        $response = $this->postJson("/api/fines/{$fine->id}/payments", [])->assertOk();

        $newId = (int) $response->json('data.payment.id');
        $this->assertNotSame($mockPending->id, $newId);

        $this->assertDatabaseHas('payments', [
            'id' => $mockPending->id,
            'provider' => 'mock',
            'status' => PaymentStatus::Failed->value,
            'failure_code' => 'provider_mismatch',
            'active_obligation_key' => null,
        ]);
        $this->assertNull($mockPending->fresh()->provider_reference);

        $newPayment = Payment::query()->findOrFail($newId);
        $this->assertSame('stripe', $newPayment->provider);
        $this->assertSame(PaymentStatus::Pending, $newPayment->status);
        $this->assertSame($fine->id, $newPayment->fine_id);
        $this->assertNull($newPayment->application_id);
        $this->assertSame('cs_fine_'.$newId, $newPayment->provider_reference);
        $this->assertSame(Payment::fineObligationKey($fine->id), $newPayment->active_obligation_key);

        $this->assertSame(
            0,
            Payment::query()
                ->where('provider', 'mock')
                ->whereNotNull('provider_reference')
                ->where('provider_reference', 'like', 'cs_%')
                ->count()
        );
    }

    public function test_same_provider_stripe_reuse_remains_idempotent(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen();
        Sanctum::actingAs($citizen);

        $first = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.payment.id');
        $second = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.payment.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::query()->where('fine_id', $fine->id)->count());
        $this->assertSame('stripe', Payment::query()->findOrFail($first)->provider);
    }

    public function test_fine_checkout_return_urls_point_to_backend_payment_pages(): void
    {
        app()->setLocale('ar');
        $service = app(StripePaymentGatewayService::class);

        $success = $service->buildFineSuccessUrl('ar');
        $cancel = $service->buildFineCancelUrl('en');

        $this->assertStringContainsString('/payment/success', $success);
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $success);
        $this->assertStringContainsString('lang=ar', $success);
        $this->assertStringContainsString('/payment/cancel', $cancel);
        $this->assertStringContainsString('lang=en', $cancel);
        $this->assertStringNotContainsString('localhost:3000', $success);
        $this->assertStringNotContainsString('localhost:3000', $cancel);
    }

    public function test_stripe_manual_confirm_disabled(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.payment.id');
        $this->postJson("/api/fines/{$fine->id}/payments/{$paymentId}/confirm", [])
            ->assertStatus(400);
    }

    public function test_webhook_completes_fine_payment_idempotently(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen(40.00);
        Sanctum::actingAs($citizen);

        $create = $this->postJson("/api/fines/{$fine->id}/payments", [])->assertOk();
        $paymentId = (int) $create->json('data.payment.id');

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'provider' => 'stripe',
            'status' => PaymentStatus::Pending->value,
            'fine_id' => $fine->id,
            'application_id' => null,
            'provider_reference' => 'cs_fine_'.$paymentId,
        ]);

        $minor = Money::toMinorUnits('40.00', 'USD');

        $payload = json_encode([
            'id' => 'evt_fine_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_fine_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => $minor,
                    'currency' => 'usd',
                    'payment_intent' => 'pi_fine',
                    'metadata' => [
                        'payment_id' => (string) $paymentId,
                        'payment_type' => 'fine',
                        'fine_id' => (string) $fine->id,
                    ],
                ],
            ],
        ]);

        $header = StripeWebhookTestSigner::sign($payload, self::WebhookSecret);

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertOk();

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'provider' => 'stripe',
            'status' => PaymentStatus::Completed->value,
            'fine_id' => $fine->id,
            'application_id' => null,
        ]);
        $this->assertDatabaseHas('fines', [
            'id' => $fine->id,
            'status' => FineStatus::Paid->value,
        ]);
        $this->assertNotNull($fine->fresh()->paid_at);
        $this->assertSame(1, Payment::query()->where('id', $paymentId)->where('status', PaymentStatus::Completed)->count());
    }

    public function test_expired_webhook_keeps_fine_unpaid(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.payment.id');

        $payload = json_encode([
            'id' => 'evt_fine_exp',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_fine_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'status' => 'expired',
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
            'status' => PaymentStatus::Failed->value,
        ]);
        $this->assertDatabaseHas('fines', [
            'id' => $fine->id,
            'status' => FineStatus::Unpaid->value,
        ]);
    }

    public function test_amount_mismatch_does_not_mark_fine_paid(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen(25.00);
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.payment.id');

        $payload = json_encode([
            'id' => 'evt_fine_amt',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_fine_'.$paymentId,
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
        $this->assertDatabaseHas('fines', [
            'id' => $fine->id,
            'status' => FineStatus::Unpaid->value,
        ]);
    }

    public function test_currency_mismatch_does_not_mark_fine_paid(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen(25.00);
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.payment.id');
        $minor = Money::toMinorUnits('25.00', 'USD');

        $payload = json_encode([
            'id' => 'evt_fine_cur',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_fine_'.$paymentId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => $minor,
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
        $this->assertSame(FineStatus::Unpaid, $fine->fresh()->status);
    }

    public function test_status_poll_completes_when_stripe_reports_paid(): void
    {
        $this->mockFineStripeGateway();
        [$citizen, $fine] = $this->unpaidFineCitizen();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.payment.id');

        $this->getJson("/api/fines/{$fine->id}/payments/{$paymentId}/status")
            ->assertOk()
            ->assertJsonPath('data.payment.status', PaymentStatus::Completed->value)
            ->assertJsonPath('data.fine.status', FineStatus::Paid->value);
    }
}
