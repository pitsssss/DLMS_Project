<?php

namespace Tests\Feature;

use App\Enums\FineStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payments\Services\StripePaymentGatewayService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReturnPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class]);
        config(['app.url' => 'https://backend.test']);
    }

    /**
     * @return array{0: User, 1: Fine, 2: Payment}
     */
    private function finePayment(
        PaymentStatus $status = PaymentStatus::Pending,
        string $sessionId = 'cs_test_return_session_1'
    ): array {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 25.00,
            'currency' => 'USD',
            'reason' => 'Return page fine',
            'status' => $status === PaymentStatus::Completed ? FineStatus::Paid : FineStatus::Unpaid,
            'paid_at' => $status === PaymentStatus::Completed ? now() : null,
        ]);

        $payment = Payment::query()->create([
            'payment_number' => 'PAY-RET-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => null,
            'fine_id' => $fine->id,
            'fee_id' => null,
            'amount' => '25.00',
            'currency' => 'USD',
            'status' => $status,
            'provider' => 'stripe',
            'provider_reference' => $sessionId,
            'paid_at' => $status === PaymentStatus::Completed ? now() : null,
            'failed_at' => $status === PaymentStatus::Failed ? now() : null,
            'active_obligation_key' => in_array($status, [PaymentStatus::Pending, PaymentStatus::UnderVerification], true)
                ? Payment::fineObligationKey($fine->id)
                : null,
            'settled_obligation_key' => $status === PaymentStatus::Completed
                ? Payment::fineObligationKey($fine->id)
                : null,
            'metadata' => ['checkout_url' => 'https://checkout.stripe.test/secret'],
        ]);

        return [$citizen, $fine, $payment];
    }

    public function test_completed_payment_renders_success_page(): void
    {
        [, , $payment] = $this->finePayment(PaymentStatus::Completed, 'cs_test_completed_ok');

        $this->get('/payment/success?session_id=cs_test_completed_ok&lang=en')
            ->assertOk()
            ->assertSee(Lang::get('messages.payments.return.success.title', [], 'en'), false)
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertDontSee($payment->provider_reference, false)
            ->assertDontSee('checkout_url', false)
            ->assertDontSee('Return page fine', false);
    }

    public function test_pending_payment_renders_processing_without_mutation(): void
    {
        [, $fine, $payment] = $this->finePayment(PaymentStatus::Pending, 'cs_test_pending_ok');

        $paymentUpdatedAt = $payment->fresh()->updated_at?->toIso8601String();
        $fineUpdatedAt = $fine->fresh()->updated_at?->toIso8601String();
        $auditBefore = AuditLog::query()->count();
        $notificationsBefore = Notification::query()->count();

        $this->get('/payment/success?session_id=cs_test_pending_ok&lang=en')
            ->assertOk()
            ->assertSee(Lang::get('messages.payments.return.processing.title', [], 'en'), false);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('fines', [
            'id' => $fine->id,
            'status' => FineStatus::Unpaid->value,
            'paid_at' => null,
        ]);
        $this->assertSame($paymentUpdatedAt, $payment->fresh()->updated_at?->toIso8601String());
        $this->assertSame($fineUpdatedAt, $fine->fresh()->updated_at?->toIso8601String());
        $this->assertSame($auditBefore, AuditLog::query()->count());
        $this->assertSame($notificationsBefore, Notification::query()->count());
    }

    public function test_under_verification_renders_verifying_copy(): void
    {
        $this->finePayment(PaymentStatus::UnderVerification, 'cs_test_uv_ok');

        $this->get('/payment/success?session_id=cs_test_uv_ok&lang=en')
            ->assertOk()
            ->assertSee(Lang::get('messages.payments.return.verifying.title', [], 'en'), false)
            ->assertDontSee(Lang::get('messages.payments.return.success.title', [], 'en'), false);
    }

    public function test_failed_payment_does_not_claim_success(): void
    {
        $this->finePayment(PaymentStatus::Failed, 'cs_test_failed_ok');

        $this->get('/payment/success?session_id=cs_test_failed_ok&lang=en')
            ->assertOk()
            ->assertSee(Lang::get('messages.payments.return.inconclusive.title', [], 'en'), false)
            ->assertDontSee(Lang::get('messages.payments.return.success.title', [], 'en'), false);
    }

    public function test_unknown_and_missing_session_are_safe(): void
    {
        $this->get('/payment/success?session_id=invalid&lang=en')
            ->assertOk()
            ->assertSee(Lang::get('messages.payments.return.inconclusive.title', [], 'en'), false)
            ->assertDontSee('SQLSTATE', false);

        $this->get('/payment/success?lang=en')
            ->assertOk()
            ->assertSee(Lang::get('messages.payments.return.inconclusive.title', [], 'en'), false);
    }

    public function test_cancel_and_processing_routes_render(): void
    {
        $this->get('/payment/cancel?lang=en')
            ->assertOk()
            ->assertSee(Lang::get('messages.payments.return.cancel.title', [], 'en'), false);

        $this->get('/payment/processing?lang=en')
            ->assertOk()
            ->assertSee(Lang::get('messages.payments.return.processing.title', [], 'en'), false);
    }

    public function test_arabic_and_english_locale_with_rtl_ltr(): void
    {
        $this->finePayment(PaymentStatus::Completed, 'cs_test_locale_ok');

        $ar = $this->get('/payment/success?session_id=cs_test_locale_ok&lang=ar')->assertOk();
        $ar->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee(Lang::get('messages.payments.return.success.title', [], 'ar'), false);

        $en = $this->get('/payment/success?session_id=cs_test_locale_ok&lang=en')->assertOk();
        $en->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee(Lang::get('messages.payments.return.success.title', [], 'en'), false);

        $this->get('/payment/cancel?lang=ar')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(Lang::get('messages.payments.return.cancel.title', [], 'ar'), false);

        $this->get('/payment/cancel?lang=en')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee(Lang::get('messages.payments.return.cancel.title', [], 'en'), false);

        $this->get('/payment/cancel?lang=xx')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false);
    }

    public function test_completed_page_does_not_emit_duplicate_notification_or_audit(): void
    {
        [, , $payment] = $this->finePayment(PaymentStatus::Completed, 'cs_test_no_dup');

        Notification::query()->create([
            'user_id' => $payment->user_id,
            'type' => NotificationType::FinePaid->value,
            'title' => 'paid',
            'body' => 'paid',
            'data' => ['fine_id' => $payment->fine_id],
            'event_key' => NotificationType::FinePaid->value.':fine:'.$payment->fine_id,
            'read_at' => null,
        ]);

        $notificationsBefore = Notification::query()->count();
        $auditBefore = AuditLog::query()->count();

        $this->get('/payment/success?session_id=cs_test_no_dup&lang=en')->assertOk();

        $this->assertSame($notificationsBefore, Notification::query()->count());
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    public function test_cancel_page_does_not_mutate_payment_or_fine(): void
    {
        [, $fine, $payment] = $this->finePayment(PaymentStatus::Pending, 'cs_test_cancel_nomut');

        $this->get('/payment/cancel?lang=ar')->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('fines', [
            'id' => $fine->id,
            'status' => FineStatus::Unpaid->value,
        ]);
    }

    public function test_fine_stripe_checkout_urls_use_internal_return_pages(): void
    {
        $service = app(StripePaymentGatewayService::class);

        $success = $service->buildFineSuccessUrl('ar');
        $cancel = $service->buildFineCancelUrl('ar');

        $this->assertStringContainsString('/payment/success', $success);
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $success);
        $this->assertStringContainsString('lang=ar', $success);
        $this->assertStringNotContainsString('%7BCHECKOUT_SESSION_ID%7D', $success);

        $this->assertStringContainsString('/payment/cancel', $cancel);
        $this->assertStringContainsString('lang=ar', $cancel);

        $enSuccess = $service->buildFineSuccessUrl('en');
        $this->assertStringContainsString('lang=en', $enSuccess);

        $fallback = $service->buildFineSuccessUrl('zz');
        $this->assertStringContainsString('lang=ar', $fallback);
    }

    public function test_cache_control_is_no_store(): void
    {
        $response = $this->get('/payment/cancel?lang=en')->assertOk();
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
    }
}
