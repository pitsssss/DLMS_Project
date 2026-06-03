<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
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

class PaymentFlowTest extends TestCase
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

    private function citizenInPaymentPending(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-T-'.strtoupper(Str::random(8)),
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

    public function test_citizen_can_view_application_fee(): void
    {
        [$citizen, $application] = $this->citizenInPaymentPending();

        Sanctum::actingAs($citizen);

        $this->getJson("/api/applications/{$application->id}/fee")
            ->assertOk()
            ->assertJsonPath('data.fee.code', 'application_fee')
            ->assertJsonPath('data.application_status', ApplicationStatus::PaymentPending->value);
    }

    public function test_citizen_can_create_and_confirm_payment_then_application_is_appointment_pending(): void
    {
        [$citizen, $application] = $this->citizenInPaymentPending();

        Sanctum::actingAs($citizen);

        $create = $this->postJson("/api/applications/{$application->id}/payments", []);
        $create->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::Pending->value);

        $paymentId = (int) $create->json('data.id');

        $confirm = $this->postJson("/api/applications/{$application->id}/payments/{$paymentId}/confirm", []);
        $confirm->assertOk()
            ->assertJsonPath('data.payment.status', PaymentStatus::Completed->value)
            ->assertJsonPath('data.application.status', ApplicationStatus::AppointmentPending->value);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::AppointmentPending->value,
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => PaymentStatus::Completed->value,
        ]);
    }

    public function test_create_payment_is_idempotent_when_pending_exists(): void
    {
        [$citizen, $application] = $this->citizenInPaymentPending();

        Sanctum::actingAs($citizen);

        $first = $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.id');
        $second = $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.id');

        $this->assertSame($first, $second);
    }

    public function test_cannot_create_payment_when_not_payment_pending(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-T-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'issued_at' => null,
        ]);

        Sanctum::actingAs($citizen);

        $this->postJson("/api/applications/{$application->id}/payments", [])
            ->assertStatus(422);
    }

    public function test_cannot_confirm_payment_twice(): void
    {
        [$citizen, $application] = $this->citizenInPaymentPending();

        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$application->id}/payments", [])->json('data.id');

        $this->postJson("/api/applications/{$application->id}/payments/{$paymentId}/confirm", [])->assertOk();

        $this->postJson("/api/applications/{$application->id}/payments/{$paymentId}/confirm", [])
            ->assertStatus(422);
    }

    public function test_list_payments_for_application(): void
    {
        [$citizen, $application] = $this->citizenInPaymentPending();

        Sanctum::actingAs($citizen);

        $this->postJson("/api/applications/{$application->id}/payments", []);

        $this->getJson("/api/applications/{$application->id}/payments")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
