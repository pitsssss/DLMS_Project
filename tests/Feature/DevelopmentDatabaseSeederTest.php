<?php

namespace Tests\Feature;

use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Models\Fee;
use App\Models\Fine;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Support\CitizenFinePaymentDemoKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevelopmentDatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_local_seed_includes_catalogs_lifecycle_and_fine_payment_demo(): void
    {
        $this->assertTrue(app()->environment(['local', 'testing']));

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Role::query()->where('name', 'citizen')->exists());
        $this->assertTrue(LicenseType::query()->where('code', 'private')->exists());
        $this->assertTrue(ServiceType::query()->where('code', 'new_license')->exists());
        $this->assertTrue(Fee::query()->where('code', 'application_fee')->exists());

        $this->assertTrue(
            LicenseApplication::query()->where('application_number', 'like', 'FLOW-%')->exists(),
            'FullLifecycle FLOW-* applications must exist after unified seed'
        );

        foreach ([
            CitizenFinePaymentDemoKit::HAPPY_EMAIL,
            CitizenFinePaymentDemoKit::MIXED_EMAIL,
            CitizenFinePaymentDemoKit::BLOCKED_EMAIL,
            CitizenFinePaymentDemoKit::OTHER_EMAIL,
        ] as $email) {
            $this->assertTrue(
                User::query()->where('email', $email)->exists(),
                "Missing Fine Payment demo citizen [{$email}]"
            );
        }

        foreach (['FINE-01', 'FINE-02', 'FINE-03', 'FINE-04', 'FINE-05', 'FINE-06', 'FINE-07', 'FINE-08'] as $code) {
            $this->assertTrue(
                Fine::query()->where('reason', 'like', '%[CFP-'.$code.']%')->exists(),
                "Missing Fine scenario marker [{$code}]"
            );
        }

        $this->assertTrue(
            Fine::query()->where('reason', 'like', '%[CFP-FINE-01]%')->where('status', FineStatus::Unpaid)->where('currency', 'USD')->exists()
        );
        $this->assertTrue(
            Payment::query()->where('payment_number', 'like', CitizenFinePaymentDemoKit::PAY_PREFIX.'%')->exists()
        );
        $this->assertTrue(
            Payment::query()->where('payment_number', CitizenFinePaymentDemoKit::PAY_PREFIX.'FINE-03-COMPLETED')
                ->where('status', PaymentStatus::Completed)
                ->where('currency', 'USD')
                ->exists()
        );
        $this->assertTrue(
            Payment::query()
                ->where('user_id', User::query()->where('email', CitizenFinePaymentDemoKit::MIXED_EMAIL)->value('id'))
                ->whereNotNull('application_id')
                ->whereNull('fine_id')
                ->exists(),
            'Mixed My Payments citizen must have application-linked payments'
        );
    }

    public function test_production_seed_skips_development_demo_data(): void
    {
        $this->app['env'] = 'production';

        // --force skips Laravel's interactive production confirmation prompt.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->assertSuccessful();

        $this->assertTrue(Role::query()->where('name', 'citizen')->exists());
        $this->assertTrue(Fee::query()->where('code', 'application_fee')->exists());

        $this->assertFalse(
            User::query()->where('email', CitizenFinePaymentDemoKit::HAPPY_EMAIL)->exists()
        );
        $this->assertFalse(
            Fine::query()->where('reason', 'like', '%[CFP-%')->exists()
        );
        $this->assertFalse(
            Payment::query()->where('payment_number', 'like', CitizenFinePaymentDemoKit::PAY_PREFIX.'%')->exists()
        );
        $this->assertFalse(
            LicenseApplication::query()->where('application_number', 'like', 'FLOW-%')->exists()
        );
    }
}
