<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Models\ApplicationDocument;
use App\Models\AppointmentSlot;
use App\Models\Fee;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\RequiredDocument;
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

class LicenseVerificationTest extends TestCase
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

    private function issueLicense(): License
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $issuer = User::factory()->dashboardEmployee('license_employee')->create();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-VF-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Approved,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $fee = Fee::query()
            ->where('license_type_id', $licenseType->id)
            ->where('service_type_id', $serviceType->id)
            ->where('code', 'application_fee')
            ->firstOrFail();

        Payment::query()->create([
            'payment_number' => 'PAY-VF-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'provider_reference' => 'mock-vf',
            'paid_at' => now(),
            'metadata' => [],
        ]);

        foreach (RequiredDocument::query()->where('is_active', true)->where('is_required', true)->get() as $rd) {
            ApplicationDocument::query()->create([
                'application_id' => $application->id,
                'required_document_id' => $rd->id,
                'file_path' => 'application_documents/test.pdf',
                'original_name' => 'test.pdf',
                'mime_type' => 'application/pdf',
                'size' => 100,
                'status' => DocumentStatus::Approved,
                'reviewed_at' => now(),
            ]);
        }

        foreach (TestType::query()->where('is_required', true)->orderBy('sequence_order')->get() as $testType) {
            $slot = AppointmentSlot::query()->where('test_type_id', $testType->id)->firstOrFail();
            $appointment = TestAppointment::query()->create([
                'application_id' => $application->id,
                'citizen_id' => $citizen->id,
                'appointment_slot_id' => $slot->id,
                'test_type_id' => $testType->id,
                'status' => AppointmentStatus::Completed,
                'scheduled_at' => now(),
            ]);
            TestResult::query()->create([
                'application_id' => $application->id,
                'test_appointment_id' => $appointment->id,
                'test_type_id' => $testType->id,
                'result' => TestResultStatus::Passed,
                'attempt_number' => 1,
                'recorded_by' => $issuer->id,
                'recorded_at' => now(),
            ]);
        }

        Sanctum::actingAs($issuer);
        $id = (int) $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertOk()
            ->json('data.id');

        return License::query()->findOrFail($id);
    }

    public function test_active_token_verifies_as_valid_without_pii(): void
    {
        $license = $this->issueLicense();
        $this->assertNotEmpty($license->verification_token);
        $this->assertGreaterThanOrEqual(32, strlen($license->verification_token));

        $data = $this->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['valid']);
        $this->assertSame('active', $data['status']);
        $this->assertSame('فعالة', $data['status_label']);
        $this->assertSame($license->license_number, $data['license_number']);
        $this->assertArrayNotHasKey('national_id', $data);
        $this->assertArrayNotHasKey('phone', $data);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('citizen_id', $data);
        $this->assertNotEmpty($data['holder_name']);
        $encoded = json_encode($data);
        $this->assertStringNotContainsString('national_id', $encoded);
        $this->assertStringNotContainsString('@', $encoded);
    }

    public function test_expired_blocked_renewed_and_unknown_are_invalid(): void
    {
        $license = $this->issueLicense();

        $license->update([
            'status' => LicenseStatus::Active,
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $this->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.status', 'expired');

        $license->update([
            'status' => LicenseStatus::Blocked,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
        $this->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        $license->update(['status' => LicenseStatus::Renewed]);
        $this->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        $license->update(['status' => LicenseStatus::Inactive]);
        $this->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        $unknown = $this->getJson('/api/licenses/verify/'.str_repeat('a', 40))
            ->assertOk()
            ->json('data');
        $this->assertFalse($unknown['valid']);
        $this->assertNull($unknown['license_number']);
    }
}
