<?php

namespace Tests\Feature;

use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Models\Fine;
use App\Models\License;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\CitizenFinePaymentDemoSeeder;
use Database\Seeders\Support\CitizenFinePaymentDemoKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class CitizenFinePaymentDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local or testing');

        (new CitizenFinePaymentDemoSeeder)->run();
    }

    public function test_seeder_creates_required_fine_and_payment_states(): void
    {
        $this->seed(CitizenFinePaymentDemoSeeder::class);

        $happy = User::query()->where('email', CitizenFinePaymentDemoKit::HAPPY_EMAIL)->firstOrFail();
        $mixed = User::query()->where('email', CitizenFinePaymentDemoKit::MIXED_EMAIL)->firstOrFail();
        $blocked = User::query()->where('email', CitizenFinePaymentDemoKit::BLOCKED_EMAIL)->firstOrFail();
        $other = User::query()->where('email', CitizenFinePaymentDemoKit::OTHER_EMAIL)->firstOrFail();

        $this->assertTrue(
            Fine::query()->where('citizen_id', $happy->id)->where('reason', 'like', '%[CFP-FINE-01]%')
                ->where('status', FineStatus::Unpaid)->where('currency', 'USD')->exists()
        );
        $this->assertTrue(
            Fine::query()->where('citizen_id', $happy->id)->where('reason', 'like', '%[CFP-FINE-03]%')
                ->where('status', FineStatus::Paid)->whereNotNull('paid_at')->exists()
        );
        $this->assertTrue(
            Fine::query()->where('citizen_id', $happy->id)->where('reason', 'like', '%[CFP-FINE-06]%')
                ->where('status', FineStatus::Cancelled)->exists()
        );

        $pending = Payment::query()->where('payment_number', CitizenFinePaymentDemoKit::PAY_PREFIX.'FINE-02-PENDING')->firstOrFail();
        $completed = Payment::query()->where('payment_number', CitizenFinePaymentDemoKit::PAY_PREFIX.'FINE-03-COMPLETED')->firstOrFail();
        $failed = Payment::query()->where('payment_number', CitizenFinePaymentDemoKit::PAY_PREFIX.'FINE-04-FAILED')->firstOrFail();
        $uv = Payment::query()->where('payment_number', CitizenFinePaymentDemoKit::PAY_PREFIX.'FINE-05-UV')->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $pending->status);
        $this->assertSame(PaymentStatus::Completed, $completed->status);
        $this->assertSame(PaymentStatus::Failed, $failed->status);
        $this->assertSame(PaymentStatus::UnderVerification, $uv->status);

        foreach ([$pending, $completed, $failed, $uv] as $payment) {
            $this->assertNotNull($payment->fine_id);
            $this->assertNull($payment->application_id);
            $this->assertSame('USD', $payment->currency);
            $this->assertTrue($payment->isFinePayment());
        }

        $this->assertSame(CitizenFinePaymentDemoKit::SESSION_SUCCESS, $completed->provider_reference);
        $this->assertSame(CitizenFinePaymentDemoKit::SESSION_PENDING, $pending->provider_reference);
        $this->assertSame(CitizenFinePaymentDemoKit::SESSION_VERIFYING, $uv->provider_reference);
        $this->assertSame(CitizenFinePaymentDemoKit::SESSION_FAILED, $failed->provider_reference);

        $this->assertSame(Payment::fineObligationKey((int) $pending->fine_id), $pending->active_obligation_key);
        $this->assertSame(Payment::fineObligationKey((int) $completed->fine_id), $completed->settled_obligation_key);
        $this->assertNull($failed->active_obligation_key);
        $this->assertNull($failed->settled_obligation_key);

        $paidFine = Fine::query()->findOrFail($completed->fine_id);
        $this->assertSame(FineStatus::Paid, $paidFine->status);
        $this->assertSame('25.00', (string) $completed->amount);
        $this->assertSame('30.00', (string) $paidFine->amount);

        $this->assertTrue(
            Payment::query()->where('user_id', $mixed->id)->whereNotNull('fine_id')->whereNull('application_id')->exists()
        );
        $this->assertTrue(
            Payment::query()->where('user_id', $mixed->id)->whereNotNull('application_id')->whereNull('fine_id')->exists()
        );

        foreach (['application_fee', 'renewal_fee', 'lost_replacement_fee', 'damaged_replacement_fee', 'unblock_fee', 'vision_test_fee'] as $feeCode) {
            $this->assertTrue(
                Payment::query()
                    ->where('user_id', $mixed->id)
                    ->whereNull('fine_id')
                    ->whereHas('fee', fn ($q) => $q->where('code', $feeCode))
                    ->exists(),
                "Missing application payment for fee [{$feeCode}]"
            );
        }

        foreach ([PaymentStatus::Pending, PaymentStatus::Completed, PaymentStatus::Failed, PaymentStatus::UnderVerification] as $status) {
            $this->assertTrue(
                Payment::query()->where('user_id', $mixed->id)->where('status', $status)->exists(),
                "Missing mixed history status [{$status->value}]"
            );
        }

        $this->assertTrue(
            License::query()->where('citizen_id', $blocked->id)->where('status', LicenseStatus::Blocked)->exists()
        );
        $this->assertTrue(
            Fine::query()->where('citizen_id', $blocked->id)->where('reason', 'like', '%[CFP-FINE-07]%')
                ->where('status', FineStatus::Unpaid)->exists()
        );
        $this->assertTrue(
            Fine::query()->where('citizen_id', $blocked->id)->where('reason', 'like', '%[CFP-FINE-08]%')
                ->where('status', FineStatus::Paid)->exists()
        );

        $this->assertTrue(
            Fine::query()->where('citizen_id', $other->id)->where('reason', 'like', '%[CFP-FINE-OTHER]%')->exists()
        );
        $this->assertTrue(
            Payment::query()->where('user_id', $other->id)->where('payment_number', CitizenFinePaymentDemoKit::PAY_PREFIX.'OTHER-PENDING')->exists()
        );
    }

    public function test_seeder_is_idempotent_on_second_run(): void
    {
        $this->seed(CitizenFinePaymentDemoSeeder::class);
        $firstFineCount = Fine::query()->where('reason', 'like', '%[CFP-%')->count();
        $firstPayCount = Payment::query()->where('payment_number', 'like', CitizenFinePaymentDemoKit::PAY_PREFIX.'%')->count();

        $this->seed(CitizenFinePaymentDemoSeeder::class);

        $this->assertSame($firstFineCount, Fine::query()->where('reason', 'like', '%[CFP-%')->count());
        $this->assertSame($firstPayCount, Payment::query()->where('payment_number', 'like', CitizenFinePaymentDemoKit::PAY_PREFIX.'%')->count());
        $this->assertSame(1, User::query()->where('email', CitizenFinePaymentDemoKit::HAPPY_EMAIL)->count());
    }

    public function test_seeded_data_works_with_citizen_apis_and_return_pages(): void
    {
        $this->seed(CitizenFinePaymentDemoSeeder::class);

        $happy = User::query()->where('email', CitizenFinePaymentDemoKit::HAPPY_EMAIL)->firstOrFail();
        $mixed = User::query()->where('email', CitizenFinePaymentDemoKit::MIXED_EMAIL)->firstOrFail();
        $other = User::query()->where('email', CitizenFinePaymentDemoKit::OTHER_EMAIL)->firstOrFail();

        $unpaid = Fine::query()->where('citizen_id', $happy->id)->where('reason', 'like', '%[CFP-FINE-01]%')->firstOrFail();
        $otherFine = Fine::query()->where('citizen_id', $other->id)->where('reason', 'like', '%[CFP-FINE-OTHER]%')->firstOrFail();
        $otherPayment = Payment::query()->where('payment_number', CitizenFinePaymentDemoKit::PAY_PREFIX.'OTHER-PENDING')->firstOrFail();

        Sanctum::actingAs($happy);
        $this->getJson('/api/fines')->assertOk();
        $this->getJson("/api/fines/{$unpaid->id}")
            ->assertOk()
            ->assertJsonPath('data.status', FineStatus::Unpaid->value)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.is_payable', true);
        $this->getJson("/api/fines/{$otherFine->id}")->assertNotFound();

        Sanctum::actingAs($mixed);
        $list = $this->getJson('/api/payments')->assertOk()->json('data');
        $this->assertNotEmpty($list['items']);
        $this->assertArrayHasKey('pagination', $list);

        $ownPayment = Payment::query()
            ->where('user_id', $mixed->id)
            ->where('payment_number', CitizenFinePaymentDemoKit::PAY_PREFIX.'FINE-MIX-COMPLETED')
            ->firstOrFail();
        $this->getJson("/api/payments/{$ownPayment->id}")->assertOk();
        $this->getJson("/api/payments/{$otherPayment->id}")->assertNotFound();

        $this->get('/payment/success?session_id='.CitizenFinePaymentDemoKit::SESSION_SUCCESS.'&lang=en')
            ->assertOk()
            ->assertSee('Payment completed successfully', false);
        $this->get('/payment/success?session_id='.CitizenFinePaymentDemoKit::SESSION_PENDING.'&lang=en')
            ->assertOk()
            ->assertSee('Confirming your payment', false);
        $this->get('/payment/success?session_id='.CitizenFinePaymentDemoKit::SESSION_VERIFYING.'&lang=en')
            ->assertOk()
            ->assertSee('Payment received', false);
    }
}
