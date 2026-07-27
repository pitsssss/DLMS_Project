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
use Tests\TestCase;

class PaymentConcurrencyAndIntegrityTest extends TestCase
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

    public function test_money_to_minor_units_exact_without_float(): void
    {
        $this->assertSame(1025, Money::toMinorUnits('10.25', 'USD'));
        $this->assertSame(1500000, Money::toMinorUnits('15000.00', 'SYP'));
        $this->assertSame(15000, Money::toMinorUnits('15000', 'JPY'));
        $this->assertTrue(Money::equals('10.25', '10.2500'));
    }

    public function test_money_rejects_excess_precision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toMinorUnits('10.255', 'USD');
    }

    public function test_duplicate_initiation_reuses_active_attempt(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-CI-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::PaymentPending,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($citizen);

        $first = $this->postJson("/api/applications/{$application->id}/payments", [])->assertOk()->json('data.id');
        $second = $this->postJson("/api/applications/{$application->id}/payments", [])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::query()->where('application_id', $application->id)->whereNull('fine_id')->count());
    }

    public function test_provider_reference_unique_constraint(): void
    {
        $citizen = User::factory()->create();
        $fee = Fee::query()->where('code', 'application_fee')->firstOrFail();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-UQ-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::PaymentPending,
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-UQ-1',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => 10,
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
            'provider' => 'stripe',
            'provider_reference' => 'cs_unique_ref',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Payment::query()->create([
            'payment_number' => 'PAY-UQ-2',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => 10,
            'currency' => 'USD',
            'status' => PaymentStatus::Failed,
            'provider' => 'stripe',
            'provider_reference' => 'cs_unique_ref',
        ]);
    }

    public function test_settled_obligation_key_unique(): void
    {
        $citizen = User::factory()->create();
        $fee = Fee::query()->where('code', 'application_fee')->firstOrFail();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-SK-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::PaymentPending,
        ]);

        $key = Payment::obligationKey($application->id, $fee->id);
        Payment::query()->create([
            'payment_number' => 'PAY-SK-1',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => 10,
            'currency' => 'SYP',
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'settled_obligation_key' => $key,
            'paid_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Payment::query()->create([
            'payment_number' => 'PAY-SK-2',
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => 10,
            'currency' => 'SYP',
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'settled_obligation_key' => $key,
            'paid_at' => now(),
        ]);
    }
}
