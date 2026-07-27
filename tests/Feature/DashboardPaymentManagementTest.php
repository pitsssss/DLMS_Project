<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Fee;
use App\Models\Fine;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Payments\Services\StripePaymentGatewayService;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use App\Modules\Payments\Support\Money;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Stripe\Checkout\Session as CheckoutSession;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardPaymentManagementTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->seed([
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

    private function asPaymentViewer(): User
    {
        $role = Role::query()->where('name', 'payment_employee')->firstOrFail();
        // Ensure view but strip manage for some tests via dedicated helper.
        $user = User::factory()->dashboardEmployee('payment_employee')->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function asPaymentManager(): User
    {
        $user = User::factory()->dashboardEmployee('payment_employee')->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function asViewOnlyPayments(): User
    {
        $role = Role::query()->create([
            'name' => 'payments_viewer_'.Str::random(4),
            'display_name' => 'Payments Viewer',
        ]);
        $ids = Permission::query()->whereIn('name', ['access_dashboard', 'view_payments'])->pluck('id');
        $role->permissions()->sync($ids);

        $user = User::factory()->create([
            'user_type' => UserType::Employee,
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed' => true,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{0: User, 1: LicenseApplication, 2: Fee}
     */
    private function citizenApplicationFee(string $serviceCode = 'new_license'): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', $serviceCode)->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-DP-'.strtoupper(Str::random(8)),
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

    private function makePayment(User $citizen, LicenseApplication $application, Fee $fee, array $overrides = []): Payment
    {
        $status = $overrides['status'] ?? PaymentStatus::Pending;
        $key = Payment::obligationKey($application->id, $fee->id);

        return Payment::query()->create(array_merge([
            'payment_number' => 'PAY-DP-'.strtoupper(Str::random(8)),
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
            'metadata' => ['checkout_url' => 'https://checkout.example/secret'],
        ], $overrides));
    }

    public function test_unauthenticated_list_returns_401(): void
    {
        $this->getJson('/api/dashboard/payments')->assertUnauthorized();
    }

    public function test_citizen_cannot_access_dashboard_payments(): void
    {
        Sanctum::actingAs(User::factory()->create(['user_type' => UserType::Citizen]));
        $this->getJson('/api/dashboard/payments')->assertForbidden();
    }

    public function test_employee_without_permission_returns_403(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('fines_employee')->create());
        $this->getJson('/api/dashboard/payments')->assertForbidden();
    }

    public function test_view_payments_can_list_and_cannot_verify(): void
    {
        $this->asViewOnlyPayments();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $payment = $this->makePayment($citizen, $application, $fee, [
            'provider' => 'stripe',
            'provider_reference' => 'cs_test_1',
        ]);

        $this->getJson('/api/dashboard/payments')->assertOk();
        $this->postJson("/api/dashboard/payments/{$payment->id}/verify")->assertForbidden();
    }

    public function test_list_excludes_fine_linked_payments(): void
    {
        $this->asPaymentManager();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $appPayment = $this->makePayment($citizen, $application, $fee);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 1000,
            'reason' => 'test',
            'status' => 'unpaid',
        ]);

        $finePayment = Payment::query()->create([
            'payment_number' => 'PAY-FINE-'.strtoupper(Str::random(6)),
            'user_id' => $citizen->id,
            'application_id' => null,
            'fine_id' => $fine->id,
            'fee_id' => null,
            'amount' => 1000,
            'currency' => 'SYP',
            'status' => PaymentStatus::Pending,
            'provider' => 'mock',
        ]);

        $ids = collect($this->getJson('/api/dashboard/payments')->assertOk()->json('data.items'))->pluck('id');
        $this->assertTrue($ids->contains($appPayment->id));
        $this->assertFalse($ids->contains($finePayment->id));
    }

    public function test_search_and_filters_and_pagination(): void
    {
        $this->asPaymentManager();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $payment = $this->makePayment($citizen, $application, $fee, [
            'payment_number' => 'PAY-SEARCHABLE-001',
            'status' => PaymentStatus::Completed,
            'settled_obligation_key' => Payment::obligationKey($application->id, $fee->id),
            'active_obligation_key' => null,
            'paid_at' => now(),
        ]);

        $this->getJson('/api/dashboard/payments?search=PAY-SEARCHABLE-001')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $payment->id);

        $this->getJson('/api/dashboard/payments?status=completed')
            ->assertOk()
            ->assertJsonPath('data.items.0.status.value', 'completed');

        $this->getJson('/api/dashboard/payments?per_page=200')->assertUnprocessable();
    }

    public function test_list_hides_metadata_and_serializes_amount_as_string(): void
    {
        $this->asPaymentManager();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $this->makePayment($citizen, $application, $fee);

        $item = $this->getJson('/api/dashboard/payments')->assertOk()->json('data.items.0');
        $this->assertIsString($item['amount']);
        $this->assertArrayNotHasKey('metadata', $item);
        $this->assertStringNotContainsString('checkout', json_encode($item));
    }

    public function test_stats_exclude_fines_and_count_completed_only_as_revenue(): void
    {
        $this->asPaymentManager();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $this->makePayment($citizen, $application, $fee, [
            'status' => PaymentStatus::Completed,
            'amount' => ApplicationFeeCatalog::amountFor('application_fee'),
            'paid_at' => now(),
            'settled_obligation_key' => Payment::obligationKey($application->id, $fee->id),
            'active_obligation_key' => null,
        ]);
        $this->makePayment($citizen, $application, $fee, [
            'status' => PaymentStatus::Pending,
            'amount' => ApplicationFeeCatalog::amountFor('application_fee'),
            'payment_number' => 'PAY-DP-PEND-'.strtoupper(Str::random(6)),
            'active_obligation_key' => null, // force unique bypass for test stats only
            'settled_obligation_key' => null,
        ]);

        // Second pending would violate active key — create without active key for stats sample
        $data = $this->getJson('/api/dashboard/payments/stats')->assertOk()->json('data');
        $this->assertSame(1, $data['completed_operations']);
        $this->assertSame('50.00', $data['completed_amount']);
        $this->assertGreaterThanOrEqual(1, $data['pending_operations']);
    }

    public function test_due_fees_lists_payment_pending_without_completed(): void
    {
        $this->asPaymentManager();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $this->makePayment($citizen, $application, $fee, ['status' => PaymentStatus::Failed, 'active_obligation_key' => null]);

        $items = $this->getJson('/api/dashboard/payments/due-fees')->assertOk()->json('data.items');
        $appIds = collect($items)->pluck('application.id');
        $this->assertTrue($appIds->contains($application->id));

        $this->makePayment($citizen, $application, $fee, [
            'status' => PaymentStatus::Completed,
            'settled_obligation_key' => Payment::obligationKey($application->id, $fee->id),
            'active_obligation_key' => null,
            'paid_at' => now(),
            'payment_number' => 'PAY-DP-DONE-'.strtoupper(Str::random(6)),
        ]);

        $itemsAfter = $this->getJson('/api/dashboard/payments/due-fees')->assertOk()->json('data.items');
        $this->assertFalse(collect($itemsAfter)->pluck('application.id')->contains($application->id));
    }

    public function test_details_attempts_and_audit(): void
    {
        $manager = User::factory()->dashboardAdmin('admin')->create();
        Sanctum::actingAs($manager);
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $payment = $this->makePayment($citizen, $application, $fee, [
            'failure_code' => 'session_expired',
            'failure_message' => 'انتهت صلاحية جلسة الدفع.',
            'status' => PaymentStatus::Failed,
            'failed_at' => now(),
            'active_obligation_key' => null,
        ]);

        AuditLog::query()->create([
            'user_id' => $manager->id,
            'action' => 'payment.failed',
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
            'old_values' => ['status' => 'pending'],
            'new_values' => ['status' => 'failed', 'source' => 'gateway'],
        ]);

        $details = $this->getJson("/api/dashboard/payments/{$payment->id}")->assertOk()->json('data');
        $this->assertSame('failed', $details['status']['value']);
        $this->assertSame('session_expired', $details['failure']['code']);
        $this->assertArrayNotHasKey('metadata', $details);

        $this->getJson("/api/dashboard/payments/{$payment->id}/attempts")->assertOk();
        $this->getJson("/api/dashboard/payments/{$payment->id}/audit-logs")->assertOk()
            ->assertJsonPath('data.items.0.action', 'payment.failed');
    }

    public function test_payment_employee_without_view_audit_logs_denied(): void
    {
        $this->asPaymentManager();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $payment = $this->makePayment($citizen, $application, $fee);

        $this->getJson("/api/dashboard/payments/{$payment->id}/audit-logs")->assertForbidden();
    }

    public function test_fine_linked_payment_details_return_404(): void
    {
        $this->asPaymentManager();
        $citizen = User::factory()->create(['user_type' => UserType::Citizen]);
        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 500,
            'reason' => 'x',
            'status' => 'unpaid',
        ]);
        $payment = Payment::query()->create([
            'payment_number' => 'PAY-FINE-404',
            'user_id' => $citizen->id,
            'fine_id' => $fine->id,
            'amount' => 500,
            'currency' => 'SYP',
            'status' => PaymentStatus::Pending,
            'provider' => 'mock',
        ]);

        $this->getJson("/api/dashboard/payments/{$payment->id}")->assertNotFound();
    }

    public function test_verify_stripe_payment_completes_idempotently(): void
    {
        $this->asPaymentManager();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $fee->refresh();

        config([
            'payment.provider' => 'stripe',
            'payment.stripe.currency' => 'usd',
        ]);

        $amount = ApplicationFeeCatalog::amountFor('application_fee');
        $payment = $this->makePayment($citizen, $application, $fee, [
            'provider' => 'stripe',
            'provider_reference' => 'cs_verify_1',
            'amount' => $amount,
            'currency' => 'USD',
        ]);

        $this->mock(StripePaymentGatewayService::class, function ($mock) use ($amount): void {
            $mock->shouldReceive('retrieveCheckoutSession')
                ->andReturn(CheckoutSession::constructFrom([
                    'id' => 'cs_verify_1',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => Money::toMinorUnits($amount, 'USD'),
                    'currency' => 'usd',
                    'payment_intent' => 'pi_1',
                    'url' => null,
                ]));
        });

        $this->postJson("/api/dashboard/payments/{$payment->id}/verify")->assertOk()
            ->assertJsonPath('data.status.value', 'completed');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Completed->value,
        ]);

        $completedAudits = AuditLog::query()->where('action', 'payment.completed')->where('entity_id', $payment->id)->count();
        $this->postJson("/api/dashboard/payments/{$payment->id}/verify")->assertOk();
        $this->assertSame($completedAudits, AuditLog::query()->where('action', 'payment.completed')->where('entity_id', $payment->id)->count());
    }

    public function test_options_endpoint(): void
    {
        $this->asPaymentManager();
        $data = $this->getJson('/api/dashboard/payments/options')->assertOk()->json('data');
        $this->assertNotEmpty($data['statuses']);
        $this->assertNotEmpty($data['providers']);
        $usd = collect($data['currencies'])->firstWhere('value', 'USD');
        $this->assertNotNull($usd);
        $this->assertSame('دولار أمريكي', $usd['label']);
    }

    public function test_no_delete_or_mark_paid_routes(): void
    {
        $this->asPaymentManager();
        [$citizen, $application, $fee] = $this->citizenApplicationFee();
        $payment = $this->makePayment($citizen, $application, $fee);

        $this->deleteJson("/api/dashboard/payments/{$payment->id}")->assertStatus(405);
        $this->postJson("/api/dashboard/payments/{$payment->id}/mark-paid")->assertNotFound();
        $this->putJson("/api/dashboard/payments/{$payment->id}", ['status' => 'completed'])->assertStatus(405);
    }
}
