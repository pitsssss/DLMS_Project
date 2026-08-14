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
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use App\Modules\Payments\Support\Money;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardFeesManagementTest extends TestCase
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

    private function asSettingsEmployee(): User
    {
        $employee = User::factory()->dashboardEmployee('settings_employee')->create();
        Sanctum::actingAs($employee);

        return $employee;
    }

    private function asSuperAdmin(): User
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function applicationFee(): Fee
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return Fee::query()
            ->where('code', 'application_fee')
            ->where('license_type_id', $licenseType->id)
            ->where('service_type_id', $serviceType->id)
            ->firstOrFail();
    }

    public function test_unauthenticated_receives_401(): void
    {
        $this->getJson('/api/dashboard/fees')->assertUnauthorized();
    }

    public function test_citizen_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['user_type' => UserType::Citizen]));

        $this->getJson('/api/dashboard/fees')->assertForbidden();
    }

    public function test_dashboard_user_without_permission_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('payment_employee')->create());

        $this->getJson('/api/dashboard/fees')->assertForbidden();
        $this->postJson('/api/dashboard/fees', [])->assertForbidden();
    }

    public function test_settings_employee_can_list_search_filter_and_paginate(): void
    {
        $this->asSettingsEmployee();
        $fee = $this->applicationFee();

        $response = $this->getJson('/api/dashboard/fees?search=تقديم&code=application_fee&is_active=1&currency=USD&per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true);

        $items = $response->json('data.items');
        $this->assertNotEmpty($items);
        $match = collect($items)->firstWhere('id', $fee->id);
        $this->assertNotNull($match);
        $this->assertArrayHasKey('usage', $match);
        $this->assertArrayHasKey('actions', $match);
        $this->assertSame('50.00', $match['amount']);
        $this->assertStringNotContainsString('messages.', $match['code_label']);
    }

    public function test_options_return_arabic_labels_for_all_catalog_codes(): void
    {
        $this->asSettingsEmployee();

        $data = $this->getJson('/api/dashboard/fees/options')->assertOk()->json('data');
        $codes = collect($data['fee_codes'])->pluck('value')->all();

        $this->assertEquals(ApplicationFeeCatalog::catalogCodes(), $codes);
        foreach ($data['fee_codes'] as $option) {
            $this->assertStringNotContainsString('messages.', $option['label']);
            $this->assertNotSame($option['value'], $option['label']);
        }
        $this->assertSame('فعّالة', collect($data['active_states'])->firstWhere('value', 'true')['label']);
    }

    public function test_stats_return_catalog_metrics(): void
    {
        $this->asSettingsEmployee();

        $stats = $this->getJson('/api/dashboard/fees/stats')->assertOk()->json('data');
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('active', $stats);
        $this->assertArrayHasKey('application_fees', $stats);
        $this->assertArrayHasKey('test_fees', $stats);
        $this->assertArrayHasKey('missing_required_configurations', $stats);
        $this->assertGreaterThan(0, $stats['total']);
    }

    public function test_details_include_immutable_flag_and_policy_note(): void
    {
        $this->asSettingsEmployee();
        $fee = $this->applicationFee();

        $data = $this->getJson("/api/dashboard/fees/{$fee->id}")->assertOk()->json('data');
        $this->assertArrayHasKey('immutable_code_scope', $data);
        $this->assertArrayHasKey('pricing_policy_note', $data);
        $this->assertStringNotContainsString('messages.', $data['pricing_policy_note']);
    }

    public function test_create_rejects_float_amount_and_negative_values(): void
    {
        $this->asSettingsEmployee();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();

        $this->postJson('/api/dashboard/fees', [
            'code' => 'application_fee',
            'name' => 'رسوم تجريبية',
            'amount' => 10.5,
            'currency' => 'USD',
            'license_type_code' => $licenseType->code,
        ])->assertStatus(422);

        $this->postJson('/api/dashboard/fees', [
            'code' => 'application_fee',
            'name' => 'رسوم تجريبية',
            'amount' => '-5.00',
            'currency' => 'USD',
            'license_type_code' => $licenseType->code,
        ])->assertStatus(422);

        $this->postJson('/api/dashboard/fees', [
            'code' => 'application_fee',
            'name' => 'رسوم تجريبية',
            'amount' => '0.00',
            'currency' => 'USD',
            'license_type_code' => $licenseType->code,
        ])->assertStatus(422);
    }

    public function test_create_rejects_duplicate_identity(): void
    {
        $this->asSettingsEmployee();
        $fee = $this->applicationFee();

        $this->postJson('/api/dashboard/fees', [
            'code' => 'application_fee',
            'name' => 'نسخة مكررة',
            'amount' => '55.00',
            'currency' => 'USD',
            'license_type_code' => $fee->licenseType->code,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.fees.duplicate_identity'));
    }

    public function test_create_test_fee_update_and_version_increment(): void
    {
        $employee = $this->asSettingsEmployee();
        $fee = Fee::query()->where('code', 'theory_test_fee')->firstOrFail();

        $detail = $this->getJson("/api/dashboard/fees/{$fee->id}")->assertOk()->json('data');

        $updated = $this->patchJson("/api/dashboard/fees/{$fee->id}", [
            'version' => $detail['version'],
            'name' => 'رسوم نظري محدّثة',
            'amount' => '16.50',
            'reason' => 'تعديل سعر',
        ])->assertOk()->json('data');

        $this->assertSame('16.50', $updated['amount']);
        $this->assertSame($detail['version'] + 1, $updated['version']);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'fee',
            'entity_id' => $fee->id,
            'action' => 'fee.updated',
            'user_id' => $employee->id,
        ]);
    }

    public function test_stale_version_returns_409(): void
    {
        $this->asSettingsEmployee();
        $fee = $this->applicationFee();

        $this->patchJson("/api/dashboard/fees/{$fee->id}", [
            'version' => 999,
            'amount' => '60.00',
        ])->assertStatus(409)
            ->assertJsonPath('message', __('messages.fees.stale_version'));
    }

    public function test_unsafe_deactivation_of_required_application_fee_is_rejected(): void
    {
        $this->asSettingsEmployee();
        $fee = $this->applicationFee();

        $this->patchJson("/api/dashboard/fees/{$fee->id}/deactivate", [
            'reason' => 'محاولة تعطيل',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.fees.unsafe_deactivation'));
    }

    public function test_test_fee_can_be_deactivated(): void
    {
        $employee = $this->asSettingsEmployee();
        $fee = Fee::query()->where('code', 'vision_test_fee')->firstOrFail();

        $this->patchJson("/api/dashboard/fees/{$fee->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee.deactivated',
            'entity_type' => 'fee',
            'entity_id' => $fee->id,
            'user_id' => $employee->id,
        ]);

        $this->patchJson("/api/dashboard/fees/{$fee->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee.activated',
            'entity_type' => 'fee',
            'entity_id' => $fee->id,
            'user_id' => $employee->id,
        ]);
    }

    public function test_used_fee_cannot_change_code_or_scope(): void
    {
        $this->asSettingsEmployee();
        [$citizen, $application, $fee] = $this->paymentFixture();

        Payment::query()->create([
            'payment_number' => 'PAY-FEE-USED',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => Money::format((string) $fee->amount),
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'paid_at' => now(),
        ]);

        $this->patchJson("/api/dashboard/fees/{$fee->id}", [
            'version' => (int) $fee->version,
            'code' => 'renewal_fee',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.fees.immutable_scope_when_used'));
    }

    public function test_audit_logs_require_view_audit_logs_permission(): void
    {
        $fee = $this->applicationFee();
        $this->asSettingsEmployee();

        $this->getJson("/api/dashboard/fees/{$fee->id}/audit-logs")->assertForbidden();

        $this->asSuperAdmin();
        $this->getJson("/api/dashboard/fees/{$fee->id}/audit-logs")
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'pagination']]);
    }

    public function test_seeder_rerun_does_not_overwrite_admin_edited_amount(): void
    {
        $fee = $this->applicationFee();
        Fee::query()->whereKey($fee->id)->update(['amount' => '77.77', 'version' => 5]);

        $this->artisan('db:seed', ['--class' => FeesSeeder::class]);

        $fee->refresh();
        $this->assertSame('77.77', Money::format((string) $fee->amount));
    }

    public function test_fee_update_does_not_rewrite_payment_snapshots(): void
    {
        $this->asSettingsEmployee();
        [$citizen, $application, $fee] = $this->paymentFixture();

        $payment = Payment::query()->create([
            'payment_number' => 'PAY-SNAPSHOT',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => '50.00',
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'paid_at' => now(),
        ]);

        $this->patchJson("/api/dashboard/fees/{$fee->id}", [
            'version' => (int) $fee->version,
            'amount' => '99.99',
        ])->assertOk();

        $payment->refresh();
        $this->assertSame('50.00', Money::format((string) $payment->amount));
    }

    public function test_new_payments_use_updated_fee_amount(): void
    {
        config(['payment.provider' => 'mock']);
        $this->asSettingsEmployee();
        [$citizen, $application, $fee] = $this->paymentFixture();

        $this->patchJson("/api/dashboard/fees/{$fee->id}", [
            'version' => (int) $fee->version,
            'amount' => '61.00',
        ])->assertOk();

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$application->id}/payments", [])->assertOk();

        $this->assertDatabaseHas('payments', [
            'application_id' => $application->id,
            'amount' => '61.00',
            'currency' => 'USD',
        ]);
    }

    public function test_missing_inactive_fee_fails_payment_resolution_safely(): void
    {
        config(['payment.provider' => 'mock']);
        $this->asSettingsEmployee();
        [$citizen, $application, $fee] = $this->paymentFixture();

        Fee::query()->whereKey($fee->id)->update(['is_active' => false]);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$application->id}/payments", [])
            ->assertStatus(422);
    }

    public function test_fine_records_are_not_affected_by_fee_updates(): void
    {
        $this->asSettingsEmployee();
        $citizen = User::factory()->withApprovedProfile()->create();
        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => '100.00',
            'reason' => 'مخالفة مرورية',
            'status' => 'unpaid',
        ]);

        $fee = $this->applicationFee();
        $this->patchJson("/api/dashboard/fees/{$fee->id}", [
            'version' => (int) $fee->version,
            'amount' => '88.00',
        ])->assertOk();

        $fine->refresh();
        $this->assertSame('100.00', Money::format((string) $fine->amount));
    }

    public function test_create_writes_audit_log_entry(): void
    {
        $employee = $this->asSuperAdmin();
        $licenseType = LicenseType::query()->where('code', 'truck')->firstOrFail();

        Fee::query()
            ->where('code', 'application_fee')
            ->where('license_type_id', $licenseType->id)
            ->delete();

        $created = $this->postJson('/api/dashboard/fees', [
            'code' => 'application_fee',
            'name' => 'رسوم دراجة',
            'amount' => '45.00',
            'currency' => 'USD',
            'license_type_code' => $licenseType->code,
        ])->assertCreated()->json('data');

        $this->assertTrue(
            AuditLog::query()
                ->where('entity_type', 'fee')
                ->where('entity_id', $created['id'])
                ->where('action', 'fee.created')
                ->where('user_id', $employee->id)
                ->exists()
        );
    }

    /**
     * @return array{0: User, 1: LicenseApplication, 2: Fee}
     */
    private function paymentFixture(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-FEE-'.uniqid(),
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
}
