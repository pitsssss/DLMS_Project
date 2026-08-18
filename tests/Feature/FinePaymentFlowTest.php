<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Enums\ServiceCode;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Applications\Services\LicenseServiceEligibilityService;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinePaymentFlowTest extends TestCase
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
        config(['payment.provider' => 'mock']);
    }

    private function citizenWithUnpaidFine(float $amount = 25.00): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => $amount,
            'currency' => 'USD',
            'reason' => 'Speeding',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        return [$citizen, $fine];
    }

    public function test_owner_can_show_fine_and_other_citizen_gets_404(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine();
        $other = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);

        Sanctum::actingAs($citizen);
        $this->getJson("/api/fines/{$fine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $fine->id)
            ->assertJsonPath('data.amount', '25.00')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.is_payable', true);

        Sanctum::actingAs($other);
        $this->getJson("/api/fines/{$fine->id}")->assertNotFound();
    }

    public function test_citizen_can_create_and_confirm_mock_fine_payment(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine(30.00);
        Sanctum::actingAs($citizen);

        $create = $this->postJson("/api/fines/{$fine->id}/payments", []);
        $create->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::Pending->value)
            ->assertJsonPath('data.amount', '30.00')
            ->assertJsonPath('data.currency', 'USD');

        $paymentId = (int) $create->json('data.id');

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'fine_id' => $fine->id,
            'application_id' => null,
            'user_id' => $citizen->id,
            'active_obligation_key' => Payment::fineObligationKey($fine->id),
        ]);

        $this->postJson("/api/fines/{$fine->id}/payments/{$paymentId}/confirm", [])
            ->assertOk()
            ->assertJsonPath('data.payment.status', PaymentStatus::Completed->value)
            ->assertJsonPath('data.fine.status', FineStatus::Paid->value);

        $this->assertDatabaseHas('fines', [
            'id' => $fine->id,
            'status' => FineStatus::Paid->value,
        ]);
        $this->assertNotNull($fine->fresh()->paid_at);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::FinePaid->value)
                ->count()
        );
        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::PaymentCompleted->value)
                ->count()
        );
    }

    public function test_rejects_amount_currency_override_and_already_paid_or_cancelled(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine();
        Sanctum::actingAs($citizen);

        $this->postJson("/api/fines/{$fine->id}/payments", [
            'amount' => 1,
            'currency' => 'EUR',
        ])->assertStatus(422);

        $paid = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 10,
            'currency' => 'USD',
            'reason' => 'paid',
            'status' => FineStatus::Paid,
            'paid_at' => now(),
        ]);
        $this->postJson("/api/fines/{$paid->id}/payments", [])->assertStatus(422);

        $cancelled = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 10,
            'currency' => 'USD',
            'reason' => 'cancelled',
            'status' => FineStatus::Cancelled,
            'paid_at' => null,
        ]);
        $this->postJson("/api/fines/{$cancelled->id}/payments", [])->assertStatus(422);
    }

    public function test_ownership_and_nested_payment_binding(): void
    {
        [$owner, $fine] = $this->citizenWithUnpaidFine();
        $other = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);

        Sanctum::actingAs($owner);
        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');

        Sanctum::actingAs($other);
        $this->postJson("/api/fines/{$fine->id}/payments", [])->assertNotFound();
        $this->getJson("/api/fines/{$fine->id}/payments/{$paymentId}/status")->assertNotFound();
        $this->postJson("/api/fines/{$fine->id}/payments/{$paymentId}/confirm", [])->assertNotFound();

        [$owner2, $fine2] = $this->citizenWithUnpaidFine(12);
        Sanctum::actingAs($owner);
        $this->getJson("/api/fines/{$fine2->id}/payments/{$paymentId}/status")->assertNotFound();
    }

    public function test_duplicate_create_reuses_active_payment(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine();
        Sanctum::actingAs($citizen);

        $first = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');
        $second = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::query()->where('fine_id', $fine->id)->count());
        $this->assertSame('mock', Payment::query()->findOrFail($first)->provider);
    }

    public function test_does_not_reuse_pending_stripe_payment_when_configured_provider_is_mock(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine(40.00);
        Sanctum::actingAs($citizen);

        $stripePending = Payment::query()->create([
            'payment_number' => 'PAY-STRIPE-PENDING-'.uniqid(),
            'user_id' => $citizen->id,
            'application_id' => null,
            'fine_id' => $fine->id,
            'fee_id' => null,
            'payable_type' => null,
            'payable_id' => null,
            'amount' => '40.00',
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
            'provider' => 'stripe',
            'provider_reference' => 'cs_test_existing_session',
            'paid_at' => null,
            'metadata' => [
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_existing_session',
                'stripe_session_id' => 'cs_test_existing_session',
            ],
            'active_obligation_key' => Payment::fineObligationKey($fine->id),
            'settled_obligation_key' => null,
        ]);

        $newId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->assertOk()->json('data.id');
        $this->assertNotSame($stripePending->id, $newId);

        $this->assertDatabaseHas('payments', [
            'id' => $stripePending->id,
            'provider' => 'stripe',
            'status' => PaymentStatus::Failed->value,
            'failure_code' => 'provider_mismatch',
            'active_obligation_key' => null,
            'provider_reference' => 'cs_test_existing_session',
        ]);

        $newPayment = Payment::query()->findOrFail($newId);
        $this->assertSame('mock', $newPayment->provider);
        $this->assertSame(PaymentStatus::Pending, $newPayment->status);
        $this->assertNull($newPayment->provider_reference);
        $this->assertSame(Payment::fineObligationKey($fine->id), $newPayment->active_obligation_key);
    }

    public function test_failed_attempt_allows_new_payment(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');
        Payment::query()->whereKey($paymentId)->update([
            'status' => PaymentStatus::Failed,
            'active_obligation_key' => null,
            'failure_code' => 'session_expired',
        ]);

        $newId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');
        $this->assertNotSame($paymentId, $newId);
        $this->assertSame(2, Payment::query()->where('fine_id', $fine->id)->count());
    }

    public function test_status_endpoint_and_cannot_confirm_completed_twice_as_new_obligation(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');
        $this->postJson("/api/fines/{$fine->id}/payments/{$paymentId}/confirm", [])->assertOk();

        $this->getJson("/api/fines/{$fine->id}/payments/{$paymentId}/status")
            ->assertOk()
            ->assertJsonPath('data.payment.status', PaymentStatus::Completed->value)
            ->assertJsonPath('data.fine.status', FineStatus::Paid->value);

        $this->postJson("/api/fines/{$fine->id}/payments", [])->assertStatus(422);
    }

    public function test_manual_paid_race_completes_payment_without_duplicate_fine_paid_notification(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');

        $fine->update([
            'status' => FineStatus::Paid,
            'paid_at' => now(),
        ]);

        Notification::query()->create([
            'user_id' => $citizen->id,
            'type' => NotificationType::FinePaid->value,
            'title' => 't',
            'body' => 'b',
            'data' => ['fine_id' => $fine->id],
            'event_key' => NotificationType::FinePaid->value.':fine:'.$fine->id,
            'read_at' => null,
        ]);

        $this->postJson("/api/fines/{$fine->id}/payments/{$paymentId}/confirm", [])->assertOk();

        $this->assertSame(PaymentStatus::Completed, Payment::query()->findOrFail($paymentId)->status);
        $this->assertSame(FineStatus::Paid, $fine->fresh()->status);
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::FinePaid->value)
                ->count()
        );
    }

    public function test_cancelled_fine_race_moves_payment_to_under_verification(): void
    {
        [$citizen, $fine] = $this->citizenWithUnpaidFine();
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');
        $fine->update(['status' => FineStatus::Cancelled]);

        $this->postJson("/api/fines/{$fine->id}/payments/{$paymentId}/confirm", [])->assertOk();

        $payment = Payment::query()->findOrFail($paymentId);
        $this->assertSame(PaymentStatus::UnderVerification, $payment->status);
        $this->assertSame(FineStatus::Cancelled, $fine->fresh()->status);
    }

    public function test_paying_fine_clears_unblock_unpaid_blocker(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now()->subYear(),
            'approved_at' => now()->subYear(),
            'issued_at' => now()->subYear(),
        ]);

        $license = License::query()->create([
            'license_number' => 'LIC-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Blocked,
            'issue_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'blocked_at' => now(),
            'block_reason' => 'test',
        ]);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => $license->id,
            'amount' => 20,
            'currency' => 'USD',
            'reason' => 'blocker',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        $eligibility = app(LicenseServiceEligibilityService::class);
        $before = $eligibility->check($citizen, $license, ServiceCode::LicenseUnblock);
        $this->assertFalse($before['allowed']);

        Sanctum::actingAs($citizen);
        $paymentId = (int) $this->postJson("/api/fines/{$fine->id}/payments", [])->json('data.id');
        $this->postJson("/api/fines/{$fine->id}/payments/{$paymentId}/confirm", [])->assertOk();

        $after = $eligibility->check($citizen, $license->fresh(), ServiceCode::LicenseUnblock);
        $this->assertTrue($after['allowed']);
        $this->assertSame(LicenseStatus::Blocked, $license->fresh()->status);
    }
}
