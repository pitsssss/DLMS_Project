<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Models\Fee;
use App\Models\Fine;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenPaymentHistoryTest extends TestCase
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

    /**
     * @return array{0: User, 1: LicenseApplication, 2: Fee}
     */
    private function citizenWithApplication(string $serviceCode = 'new_license'): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', $serviceCode)->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-PH-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::PaymentPending,
            'submitted_at' => now(),
        ]);

        $feeCode = match ($serviceCode) {
            'renew_license' => 'renewal_fee',
            'lost_replacement' => 'lost_replacement_fee',
            'damaged_replacement' => 'damaged_replacement_fee',
            'license_unblock' => 'unblock_fee',
            default => 'application_fee',
        };

        $fee = Fee::query()
            ->where('code', $feeCode)
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

        return [$citizen, $application, $fee];
    }

    /**
     * @return array{0: User, 1: Fine}
     */
    private function citizenWithFine(float $amount = 25.00): array
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

    private function makeApplicationPayment(
        User $citizen,
        LicenseApplication $application,
        Fee $fee,
        array $overrides = []
    ): Payment {
        $status = $overrides['status'] ?? PaymentStatus::Completed;
        $key = Payment::obligationKey($application->id, $fee->id);

        return Payment::query()->create(array_merge([
            'payment_number' => 'PAY-PH-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fine_id' => null,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => $status,
            'provider' => 'mock',
            'provider_reference' => null,
            'paid_at' => $status === PaymentStatus::Completed ? now() : null,
            'failed_at' => $status === PaymentStatus::Failed ? now() : null,
            'active_obligation_key' => in_array($status, [PaymentStatus::Pending, PaymentStatus::UnderVerification], true) ? $key : null,
            'settled_obligation_key' => $status === PaymentStatus::Completed ? $key : null,
            'metadata' => [
                'checkout_url' => 'https://checkout.stripe.test/cs_test_secret',
                'client_secret' => 'cs_test_should_never_leak',
            ],
        ], $overrides));
    }

    private function makeFinePayment(User $citizen, Fine $fine, array $overrides = []): Payment
    {
        $status = $overrides['status'] ?? PaymentStatus::Completed;
        $key = Payment::fineObligationKey($fine->id);

        return Payment::query()->create(array_merge([
            'payment_number' => 'PAY-FINE-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => null,
            'fine_id' => $fine->id,
            'fee_id' => null,
            'amount' => $fine->amount,
            'currency' => $fine->currency ?? 'USD',
            'status' => $status,
            'provider' => 'stripe',
            'provider_reference' => 'cs_test_'.strtolower(Str::random(12)),
            'paid_at' => $status === PaymentStatus::Completed ? now() : null,
            'failed_at' => $status === PaymentStatus::Failed ? now() : null,
            'active_obligation_key' => in_array($status, [PaymentStatus::Pending, PaymentStatus::UnderVerification], true) ? $key : null,
            'settled_obligation_key' => $status === PaymentStatus::Completed ? $key : null,
            'metadata' => [
                'checkout_url' => 'https://checkout.stripe.test/cs_test_secret',
                'session_id' => 'cs_test_should_never_leak',
            ],
        ], $overrides));
    }

    public function test_empty_history_returns_200_with_empty_paginated_collection(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($citizen);

        $response = $this->getJson('/api/payments')->assertOk();

        $response->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination.total', 0)
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 15)
            ->assertJsonPath('data.pagination.last_page', 1);
    }

    public function test_fine_payment_appears_with_purpose_and_payment_amount(): void
    {
        [$citizen, $fine] = $this->citizenWithFine(25.00);
        $payment = $this->makeFinePayment($citizen, $fine, [
            'amount' => '25.00',
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
        ]);

        Sanctum::actingAs($citizen);
        $item = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/payments')
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame($payment->id, $item['id']);
        $this->assertSame('25.00', $item['amount']);
        $this->assertSame('USD', $item['currency']);
        $this->assertSame('completed', $item['status']);
        $this->assertSame('stripe', $item['provider']);
        $this->assertSame('fine', $item['purpose']['code']);
        $this->assertSame(Lang::get('messages.payments.purposes.fine', [], 'en'), $item['purpose']['label']);
        $this->assertSame('fine', $item['related']['type']);
        $this->assertSame($fine->id, $item['related']['id']);
        $this->assertArrayNotHasKey('metadata', $item);
        $this->assertArrayNotHasKey('checkout_url', $item);
        $this->assertArrayNotHasKey('provider_reference', $item);
    }

    public function test_application_payment_appears_with_fee_purpose(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication('new_license');
        $payment = $this->makeApplicationPayment($citizen, $application, $fee);

        Sanctum::actingAs($citizen);
        $item = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/payments')
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame($payment->id, $item['id']);
        $this->assertSame('application_fee', $item['purpose']['code']);
        $this->assertSame(Lang::get('messages.fees.codes.application_fee', [], 'en'), $item['purpose']['label']);
        $this->assertSame('application', $item['related']['type']);
        $this->assertSame($application->id, $item['related']['id']);
        $this->assertSame($application->application_number, $item['related']['application_number']);
        $this->assertSame('new_license', $item['related']['service_type_code']);
    }

    public function test_mixed_history_includes_fine_and_application_payments(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication();
        $appPayment = $this->makeApplicationPayment($citizen, $application, $fee);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 15,
            'currency' => 'USD',
            'reason' => 'Parking',
            'status' => FineStatus::Paid,
            'paid_at' => now(),
        ]);
        $finePayment = $this->makeFinePayment($citizen, $fine, ['amount' => '15.00']);

        Sanctum::actingAs($citizen);
        $ids = collect($this->getJson('/api/payments')->assertOk()->json('data.items'))->pluck('id');

        $this->assertTrue($ids->contains($appPayment->id));
        $this->assertTrue($ids->contains($finePayment->id));
        $this->assertCount(2, $ids);
    }

    public function test_list_excludes_other_citizen_payments(): void
    {
        [$owner, $fine] = $this->citizenWithFine();
        $ownerPayment = $this->makeFinePayment($owner, $fine);

        [$other] = $this->citizenWithFine(10);
        $otherPayment = $this->makeFinePayment($other, Fine::query()->where('citizen_id', $other->id)->first());

        Sanctum::actingAs($owner);
        $ids = collect($this->getJson('/api/payments')->assertOk()->json('data.items'))->pluck('id');

        $this->assertTrue($ids->contains($ownerPayment->id));
        $this->assertFalse($ids->contains($otherPayment->id));
    }

    public function test_foreign_payment_detail_returns_404(): void
    {
        [$owner, $fine] = $this->citizenWithFine();
        $payment = $this->makeFinePayment($owner, $fine);

        $intruder = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($intruder);

        $this->getJson("/api/payments/{$payment->id}")->assertNotFound();
    }

    public function test_owner_can_view_fine_payment_detail(): void
    {
        [$citizen, $fine] = $this->citizenWithFine(40.00);
        $fine->update(['status' => FineStatus::Paid, 'paid_at' => now()]);
        $payment = $this->makeFinePayment($citizen, $fine, [
            'amount' => '40.00',
            'status' => PaymentStatus::Completed,
        ]);

        Sanctum::actingAs($citizen);
        $data = $this->getJson("/api/payments/{$payment->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($payment->id, $data['id']);
        $this->assertSame('fine', $data['purpose']['code']);
        $this->assertSame('fine', $data['related']['type']);
        $this->assertSame($fine->id, $data['detail']['fine']['id']);
        $this->assertSame('40.00', $data['detail']['fine']['amount']);
        $this->assertSame('USD', $data['detail']['fine']['currency']);
        $this->assertSame('Speeding', $data['detail']['fine']['reason']);
        $this->assertSame(FineStatus::Paid->value, $data['detail']['fine']['status']);
        $this->assertArrayNotHasKey('metadata', $data);
        $this->assertArrayNotHasKey('checkout_url', $data);
    }

    public function test_owner_can_view_application_payment_detail(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication('license_unblock');
        $payment = $this->makeApplicationPayment($citizen, $application, $fee);

        Sanctum::actingAs($citizen);
        $data = $this->getJson("/api/payments/{$payment->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('unblock_fee', $data['purpose']['code']);
        $this->assertSame('application', $data['related']['type']);
        $this->assertSame($application->id, $data['detail']['application']['id']);
        $this->assertSame($application->application_number, $data['detail']['application']['application_number']);
        $this->assertSame('license_unblock', $data['detail']['application']['service_type_code']);
        $this->assertSame('unblock_fee', $data['detail']['fee']['code']);
    }

    public function test_ordering_is_latest_created_first(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication();

        $older = $this->makeApplicationPayment($citizen, $application, $fee, [
            'payment_number' => 'PAY-OLD-1',
            'status' => PaymentStatus::Failed,
            'paid_at' => null,
            'failed_at' => now()->subDays(2),
            'settled_obligation_key' => null,
            'active_obligation_key' => null,
        ]);
        $older->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->saveQuietly();

        $newer = $this->makeApplicationPayment($citizen, $application, $fee, [
            'payment_number' => 'PAY-NEW-1',
            'status' => PaymentStatus::Failed,
            'paid_at' => null,
            'failed_at' => now(),
            'settled_obligation_key' => null,
            'active_obligation_key' => null,
        ]);
        $newer->forceFill([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ])->saveQuietly();

        Sanctum::actingAs($citizen);
        $ids = collect($this->getJson('/api/payments')->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_pagination_spans_pages(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication();

        for ($i = 0; $i < 16; $i++) {
            $payment = $this->makeApplicationPayment($citizen, $application, $fee, [
                'payment_number' => 'PAY-PG-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => PaymentStatus::Failed,
                'paid_at' => null,
                'failed_at' => now(),
                'settled_obligation_key' => null,
                'active_obligation_key' => null,
            ]);
            $payment->forceFill([
                'created_at' => now()->subMinutes(16 - $i),
                'updated_at' => now()->subMinutes(16 - $i),
            ])->saveQuietly();
        }

        Sanctum::actingAs($citizen);

        $page1 = $this->getJson('/api/payments?per_page=10')->assertOk()->json('data');
        $this->assertCount(10, $page1['items']);
        $this->assertSame(16, $page1['pagination']['total']);
        $this->assertSame(2, $page1['pagination']['last_page']);
        $this->assertSame(10, $page1['pagination']['per_page']);
        $this->assertSame(1, $page1['pagination']['current_page']);

        $page2 = $this->getJson('/api/payments?per_page=10&page=2')->assertOk()->json('data');
        $this->assertCount(6, $page2['items']);
        $this->assertSame(2, $page2['pagination']['current_page']);
    }

    public function test_status_filter_completed_only(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication();
        $completed = $this->makeApplicationPayment($citizen, $application, $fee, [
            'status' => PaymentStatus::Completed,
        ]);
        $pending = $this->makeApplicationPayment($citizen, $application, $fee, [
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
            'settled_obligation_key' => null,
            'active_obligation_key' => Payment::obligationKey($application->id, $fee->id),
        ]);

        Sanctum::actingAs($citizen);
        $ids = collect($this->getJson('/api/payments?status=completed')->assertOk()->json('data.items'))->pluck('id');

        $this->assertTrue($ids->contains($completed->id));
        $this->assertFalse($ids->contains($pending->id));
    }

    public function test_type_fine_filter(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication();
        $appPayment = $this->makeApplicationPayment($citizen, $application, $fee);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 12,
            'currency' => 'USD',
            'reason' => 'filter',
            'status' => FineStatus::Paid,
            'paid_at' => now(),
        ]);
        $finePayment = $this->makeFinePayment($citizen, $fine, ['amount' => '12.00']);

        Sanctum::actingAs($citizen);
        $ids = collect($this->getJson('/api/payments?type=fine')->assertOk()->json('data.items'))->pluck('id');

        $this->assertTrue($ids->contains($finePayment->id));
        $this->assertFalse($ids->contains($appPayment->id));
    }

    public function test_type_application_filter(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication();
        $appPayment = $this->makeApplicationPayment($citizen, $application, $fee);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 12,
            'currency' => 'USD',
            'reason' => 'filter',
            'status' => FineStatus::Paid,
            'paid_at' => now(),
        ]);
        $finePayment = $this->makeFinePayment($citizen, $fine, ['amount' => '12.00']);

        Sanctum::actingAs($citizen);
        $ids = collect($this->getJson('/api/payments?type=application')->assertOk()->json('data.items'))->pluck('id');

        $this->assertTrue($ids->contains($appPayment->id));
        $this->assertFalse($ids->contains($finePayment->id));
    }

    public function test_invalid_status_returns_validation_error(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($citizen);

        $this->getJson('/api/payments?status=foo')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_invalid_type_returns_validation_error(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($citizen);

        $this->getJson('/api/payments?type=refund')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_arabic_purpose_labels(): void
    {
        [$citizen, $fine] = $this->citizenWithFine();
        $this->makeFinePayment($citizen, $fine);

        [$citizen2, $application, $fee] = $this->citizenWithApplication('license_unblock');
        // Reuse same citizen for mixed labels in one list is clearer — attach unblock app to first citizen.
        $application->update(['citizen_id' => $citizen->id]);
        $this->makeApplicationPayment($citizen, $application, $fee);

        Sanctum::actingAs($citizen);
        $items = collect($this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/payments')
            ->assertOk()
            ->json('data.items'));

        $fineItem = $items->firstWhere('purpose.code', 'fine');
        $unblockItem = $items->firstWhere('purpose.code', 'unblock_fee');

        $this->assertSame(Lang::get('messages.payments.purposes.fine', [], 'ar'), $fineItem['purpose']['label']);
        $this->assertSame(Lang::get('messages.fees.codes.unblock_fee', [], 'ar'), $unblockItem['purpose']['label']);
    }

    public function test_english_purpose_labels(): void
    {
        [$citizen, $fine] = $this->citizenWithFine();
        $this->makeFinePayment($citizen, $fine);

        [, $application, $fee] = $this->citizenWithApplication('new_license');
        $application->update(['citizen_id' => $citizen->id]);
        $this->makeApplicationPayment($citizen, $application, $fee);

        Sanctum::actingAs($citizen);
        $items = collect($this->withHeader('Accept-Language', 'en')
            ->getJson('/api/payments')
            ->assertOk()
            ->json('data.items'));

        $fineItem = $items->firstWhere('purpose.code', 'fine');
        $appItem = $items->firstWhere('purpose.code', 'application_fee');

        $this->assertSame(Lang::get('messages.payments.purposes.fine', [], 'en'), $fineItem['purpose']['label']);
        $this->assertSame(Lang::get('messages.fees.codes.application_fee', [], 'en'), $appItem['purpose']['label']);
    }

    public function test_machine_codes_remain_untranslated(): void
    {
        [$citizen, $fine] = $this->citizenWithFine();
        $this->makeFinePayment($citizen, $fine, [
            'status' => PaymentStatus::Completed,
            'provider' => 'stripe',
            'currency' => 'USD',
        ]);

        Sanctum::actingAs($citizen);
        $item = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/payments')
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame('completed', $item['status']);
        $this->assertSame('fine', $item['purpose']['code']);
        $this->assertSame('fine', $item['related']['type']);
        $this->assertSame('USD', $item['currency']);
        $this->assertSame('stripe', $item['provider']);
    }

    public function test_historical_amount_and_currency_integrity(): void
    {
        [$citizen, $fine] = $this->citizenWithFine(25.00);
        $payment = $this->makeFinePayment($citizen, $fine, [
            'amount' => '25.00',
            'currency' => 'USD',
        ]);

        $fine->update(['amount' => 99.00]);

        [, $application, $fee] = $this->citizenWithApplication();
        $application->update(['citizen_id' => $citizen->id]);
        $appPayment = $this->makeApplicationPayment($citizen, $application, $fee, [
            'amount' => '50.00',
            'currency' => 'USD',
        ]);
        $fee->update(['amount' => 999.00]);

        Sanctum::actingAs($citizen);
        $items = collect($this->getJson('/api/payments')->assertOk()->json('data.items'))->keyBy('id');

        $this->assertSame('25.00', $items[$payment->id]['amount']);
        $this->assertSame('USD', $items[$payment->id]['currency']);
        $this->assertSame('50.00', $items[$appPayment->id]['amount']);
        $this->assertSame('USD', $items[$appPayment->id]['currency']);
    }

    public function test_malformed_dual_linked_payment_is_excluded(): void
    {
        [$citizen, $application, $fee] = $this->citizenWithApplication();
        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 10,
            'currency' => 'USD',
            'reason' => 'malformed',
            'status' => FineStatus::Unpaid,
        ]);

        $valid = $this->makeApplicationPayment($citizen, $application, $fee);
        $malformed = Payment::query()->create([
            'payment_number' => 'PAY-BAD-DUAL',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fine_id' => $fine->id,
            'fee_id' => $fee->id,
            'amount' => '10.00',
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($citizen);
        $ids = collect($this->getJson('/api/payments')->assertOk()->json('data.items'))->pluck('id');

        $this->assertTrue($ids->contains($valid->id));
        $this->assertFalse($ids->contains($malformed->id));

        $this->getJson("/api/payments/{$malformed->id}")->assertNotFound();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/payments')->assertUnauthorized();
        $this->getJson('/api/payments/1')->assertUnauthorized();
    }
}
