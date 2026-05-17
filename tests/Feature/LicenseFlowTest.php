<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Models\ApplicationDocument;
use App\Models\AppointmentSlot;
use App\Models\Fee;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\RequiredDocument;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LicenseFlowTest extends TestCase
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
            TestTypesSeeder::class,
            FeesSeeder::class,
            RequiredDocumentsSeeder::class,
            AppointmentSlotsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function employeeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'employee')->value('id'),
        ]);
    }

    /**
     * @return array{0: User, 1: LicenseApplication}
     */
    private function approvedApplicationReadyForIssuance(): array
    {
        $citizen = User::factory()->create([
            'profile_completed' => true,
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-LIC-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Approved,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => now(),
            'issued_at' => null,
        ]);

        $fee = Fee::query()
            ->where('license_type_id', $licenseType->id)
            ->where('service_type_id', $serviceType->id)
            ->where('code', 'application_fee')
            ->firstOrFail();

        Payment::query()->create([
            'payment_number' => 'PAY-LIC-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fine_id' => null,
            'fee_id' => $fee->id,
            'payable_type' => null,
            'payable_id' => null,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'provider_reference' => 'mock-test',
            'paid_at' => now(),
            'metadata' => [],
        ]);

        $requiredDocs = RequiredDocument::query()
            ->where('is_active', true)
            ->where(function ($q) use ($application): void {
                $q->whereNull('license_type_id')->orWhere('license_type_id', $application->license_type_id);
            })
            ->where(function ($q) use ($application): void {
                $q->whereNull('service_type_id')->orWhere('service_type_id', $application->service_type_id);
            })
            ->where('is_required', true)
            ->get();

        foreach ($requiredDocs as $rd) {
            ApplicationDocument::query()->create([
                'application_id' => $application->id,
                'required_document_id' => $rd->id,
                'file_path' => 'application_documents/test.pdf',
                'original_name' => 'test.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 100,
                'status' => DocumentStatus::Approved,
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => now(),
            ]);
        }

        foreach (TestType::query()->where('is_required', true)->orderBy('sequence_order')->get() as $testType) {
            $slot = AppointmentSlot::query()
                ->where('test_type_id', $testType->id)
                ->firstOrFail();

            $appointment = TestAppointment::query()->create([
                'application_id' => $application->id,
                'citizen_id' => $citizen->id,
                'appointment_slot_id' => $slot->id,
                'test_type_id' => $testType->id,
                'status' => AppointmentStatus::Completed,
                'scheduled_at' => now(),
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);

            TestResult::query()->create([
                'application_id' => $application->id,
                'test_appointment_id' => $appointment->id,
                'test_type_id' => $testType->id,
                'result' => TestResultStatus::Passed,
                'attempt_number' => 1,
                'notes' => null,
                'recorded_by' => $this->employeeUser()->id,
                'recorded_at' => now(),
            ]);
        }

        return [$citizen, $application];
    }

    public function test_ping_reports_phase_seven(): void
    {
        $this->getJson('/api/ping')->assertOk()->assertJsonPath('data.phase', 7);
    }

    public function test_employee_can_issue_license_for_approved_application(): void
    {
        [$citizen, $application] = $this->approvedApplicationReadyForIssuance();

        Sanctum::actingAs($this->employeeUser());

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertOk()
            ->assertJsonPath('data.status', LicenseStatus::Active->value);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::LicenseIssued->value,
        ]);

        Sanctum::actingAs($citizen);

        $this->getJson('/api/licenses')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_cannot_issue_license_when_citizen_has_unpaid_fines(): void
    {
        [$citizen, $application] = $this->approvedApplicationReadyForIssuance();

        Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 5000,
            'reason' => 'Traffic violation',
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        Sanctum::actingAs($this->employeeUser());

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertStatus(422);
    }

    public function test_admin_can_create_and_mark_fine_paid(): void
    {
        [$citizen] = $this->approvedApplicationReadyForIssuance();

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'admin')->value('id'),
        ]);
        Sanctum::actingAs($admin);

        $fineId = (int) $this->postJson('/api/admin/fines', [
            'citizen_id' => $citizen->id,
            'amount' => 10000,
            'reason' => 'Late document submission',
        ])->json('data.id');

        $this->putJson("/api/admin/fines/{$fineId}", [
            'status' => FineStatus::Paid->value,
        ])->assertOk()
            ->assertJsonPath('data.status', FineStatus::Paid->value);

        Sanctum::actingAs($citizen);

        $this->getJson('/api/fines')
            ->assertOk()
            ->assertJsonPath('data.0.status', FineStatus::Paid->value);
    }

    public function test_employee_can_block_and_unblock_license(): void
    {
        [$citizen, $application] = $this->approvedApplicationReadyForIssuance();
        $employee = $this->employeeUser();

        Sanctum::actingAs($employee);
        $licenseId = (int) $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->json('data.id');

        $this->postJson("/api/admin/licenses/{$licenseId}/block", [
            'reason' => 'Fraud investigation',
        ])->assertOk()
            ->assertJsonPath('data.status', LicenseStatus::Blocked->value);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/licenses/{$licenseId}/unblock-request")->assertOk();

        Sanctum::actingAs($employee);
        $this->postJson("/api/admin/licenses/{$licenseId}/unblock")
            ->assertOk()
            ->assertJsonPath('data.status', LicenseStatus::Active->value);
    }

    public function test_citizen_can_renew_eligible_license(): void
    {
        [$citizen, $application] = $this->approvedApplicationReadyForIssuance();

        Sanctum::actingAs($this->employeeUser());
        $licenseId = (int) $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->json('data.id');

        License::query()->whereKey($licenseId)->update([
            'expiry_date' => now()->subDay()->toDateString(),
            'status' => LicenseStatus::Expired,
        ]);

        Sanctum::actingAs($citizen);

        $this->postJson("/api/licenses/{$licenseId}/renew")
            ->assertOk()
            ->assertJsonPath('data.status', LicenseStatus::Active->value);

        $this->assertDatabaseHas('licenses', [
            'id' => $licenseId,
            'status' => LicenseStatus::Renewed->value,
        ]);
    }
}
