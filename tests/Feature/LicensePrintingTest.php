<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Models\ApplicationDocument;
use App\Models\AppointmentSlot;
use App\Models\Fee;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseStatusHistory;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\RequiredDocument;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Licenses\Services\LicensePrintService;
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

class LicensePrintingTest extends TestCase
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

    private function issueLicense(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $issuer = User::factory()->dashboardEmployee('license_employee')->create();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-PR-'.strtoupper(Str::random(6)),
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
            'payment_number' => 'PAY-PR-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'provider_reference' => 'mock-pr',
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

        return [$issuer, License::query()->findOrFail($id)];
    }

    public function test_authorized_print_returns_pdf_and_tracks_metadata(): void
    {
        [$issuer, $license] = $this->issueLicense();
        Sanctum::actingAs($issuer);

        $response = $this->post("/api/dashboard/licenses/{$license->id}/print");
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $license->refresh();
        $this->assertSame(1, (int) $license->print_count);
        $this->assertSame($issuer->id, $license->printed_by);
        $this->assertNotNull($license->printed_at);

        $this->assertTrue(
            LicenseStatusHistory::query()
                ->where('license_id', $license->id)
                ->where('action', 'printed')
                ->exists()
        );

        $details = $this->getJson("/api/dashboard/licenses/{$license->id}")->assertOk()->json('data');
        $expectedUrl = rtrim((string) config('license.verification_public_url'), '/').'/'.$license->verification_token;
        $this->assertSame($expectedUrl, $details['verification']['url']);
        $this->assertSame($expectedUrl, $details['digital_license']['verification_url']);
        $this->assertStringNotContainsString('/api/licenses/verify/', (string) $details['verification']['url']);
        // Assert structure: public verify path + token only (token may coincidentally contain id digits).
        $this->assertSame(
            '/licenses/verify/'.$license->verification_token,
            parse_url((string) $details['verification']['url'], PHP_URL_PATH)
        );
        $this->assertStringNotContainsString($license->license_number, (string) $details['verification']['url']);
        $this->assertGreaterThan(2000, strlen($response->getContent()));
        $this->assertArrayHasKey('is_valid', $details['digital_license']);
        $this->assertTrue($details['digital_license']['is_valid']);
        $this->assertArrayHasKey('labels', $details['digital_license']);
        $this->assertArrayHasKey('has_portrait', $details['digital_license']);
    }

    public function test_qr_url_uses_configured_public_verification_url_and_appends_token(): void
    {
        [, $license] = $this->issueLicense();
        $license->refresh();

        config(['license.verification_public_url' => 'http://localhost:3000/licenses/verify/']);

        $url = DigitalLicensePresenter::verificationPublicUrl($license);

        $this->assertSame(
            'http://localhost:3000/licenses/verify/'.$license->verification_token,
            $url
        );
        $this->assertSame($url, DigitalLicensePresenter::payload($license)['verification_url']);
        $this->assertSame(
            '/licenses/verify/'.$license->verification_token,
            parse_url((string) $url, PHP_URL_PATH)
        );
        $this->assertStringNotContainsString('/api/licenses/verify', (string) $url);
        $this->assertStringNotContainsString($license->license_number, (string) $url);

        $png = app(LicensePrintService::class)->qrPngDataUri((string) $url);
        $this->assertStringStartsWith('data:image/png;base64,', $png);
    }

    public function test_production_style_public_verification_url_can_be_configured(): void
    {
        [, $license] = $this->issueLicense();
        $license->refresh();

        config(['license.verification_public_url' => 'https://syrtak.example/licenses/verify']);

        $url = DigitalLicensePresenter::verificationPublicUrl($license);

        $this->assertSame(
            'https://syrtak.example/licenses/verify/'.$license->verification_token,
            $url
        );
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringNotContainsString('127.0.0.1', (string) $url);
        $this->assertStringNotContainsString(':8000', (string) $url);
        $this->assertStringNotContainsString('/api/licenses/verify', (string) $url);
    }

    public function test_unauthorized_print_forbidden(): void
    {
        [, $license] = $this->issueLicense();
        Sanctum::actingAs(User::factory()->dashboardEmployee('reports_employee')->create());
        $this->postJson("/api/dashboard/licenses/{$license->id}/print")->assertForbidden();
    }
}
