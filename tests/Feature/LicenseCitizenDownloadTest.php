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
use App\Modules\Licenses\Support\DigitalLicensePresenter;
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

class LicenseCitizenDownloadTest extends TestCase
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

    public function test_citizen_can_download_own_license_pdf(): void
    {
        [$citizen, $license] = $this->issueOwnedLicense();
        Sanctum::actingAs($citizen);

        $response = $this->post("/api/licenses/{$license->id}/download");
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(2000, strlen($response->getContent()));
        $this->assertStringContainsString('SYRTAK-License-', (string) $response->headers->get('Content-Disposition'));

        $license->refresh();
        $this->assertSame(0, (int) $license->print_count);
        $this->assertNull($license->printed_by);
    }

    public function test_foreign_citizen_download_is_not_found(): void
    {
        [, $license] = $this->issueOwnedLicense();
        $stranger = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($stranger);

        $this->postJson("/api/licenses/{$license->id}/download")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_download_is_rejected(): void
    {
        [, $license] = $this->issueOwnedLicense();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/licenses/{$license->id}/download")->assertUnauthorized();
    }

    public function test_blocked_license_download_stays_invalid_in_payload(): void
    {
        [$citizen, $license] = $this->issueOwnedLicense();
        $license->update(['status' => LicenseStatus::Blocked]);

        $payload = DigitalLicensePresenter::payload($license->fresh());
        $this->assertFalse($payload['is_valid']);
        $this->assertSame('blocked', $payload['status']);

        Sanctum::actingAs($citizen);
        $blocked = $this->post("/api/licenses/{$license->id}/download");
        $blocked->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            (string) $blocked->headers->get('Content-Type')
        );
    }

    public function test_download_qr_url_matches_public_verification_contract(): void
    {
        [$citizen, $license] = $this->issueOwnedLicense();
        $license->refresh();
        $expected = rtrim((string) config('license.verification_public_url'), '/').'/'.$license->verification_token;

        $this->assertSame($expected, DigitalLicensePresenter::verificationPublicUrl($license));
        $this->assertSame(
            '/licenses/verify/'.$license->verification_token,
            parse_url((string) DigitalLicensePresenter::verificationPublicUrl($license), PHP_URL_PATH)
        );

        Sanctum::actingAs($citizen);
        $this->post("/api/licenses/{$license->id}/download")->assertOk();
    }

    /**
     * @return array{0: User, 1: License}
     */
    private function issueOwnedLicense(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $issuer = User::factory()->dashboardEmployee('license_employee')->create();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-DL-'.strtoupper(Str::random(6)),
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
            'payment_number' => 'PAY-DL-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'provider_reference' => 'mock-dl',
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

        return [$citizen, License::query()->findOrFail($id)];
    }
}
