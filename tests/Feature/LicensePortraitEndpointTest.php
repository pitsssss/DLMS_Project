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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LicensePortraitEndpointTest extends TestCase
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

    public function test_authorized_employee_receives_approved_portrait(): void
    {
        if (! function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is required to write a JPEG portrait fixture.');
        }

        [$issuer, $license] = $this->issueLicense(withPortrait: true);
        Sanctum::actingAs($issuer);

        $response = $this->get("/api/dashboard/licenses/{$license->id}/portrait");
        $response->assertOk();
        $this->assertStringContainsString('image/jpeg', (string) $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNotSame('', $response->getContent());
    }

    public function test_unauthenticated_portrait_is_rejected(): void
    {
        [, $license] = $this->issueLicense();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/dashboard/licenses/{$license->id}/portrait")->assertUnauthorized();
    }

    public function test_unauthorized_employee_cannot_view_portrait(): void
    {
        [, $license] = $this->issueLicense();
        Sanctum::actingAs(User::factory()->dashboardEmployee('reports_employee')->create());
        $this->getJson("/api/dashboard/licenses/{$license->id}/portrait")->assertForbidden();
    }

    public function test_license_without_image_portrait_returns_not_found(): void
    {
        [$issuer, $license] = $this->issueLicense(withPortrait: false);
        Sanctum::actingAs($issuer);
        $this->getJson("/api/dashboard/licenses/{$license->id}/portrait")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    /**
     * @return array{0: User, 1: License}
     */
    private function issueLicense(bool $withPortrait = false): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $issuer = User::factory()->dashboardEmployee('license_employee')->create();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-PT-'.strtoupper(Str::random(6)),
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
            'payment_number' => 'PAY-PT-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'provider_reference' => 'mock-pt',
            'paid_at' => now(),
            'metadata' => [],
        ]);

        foreach (RequiredDocument::query()->where('is_active', true)->where('is_required', true)->get() as $rd) {
            $isPhoto = $rd->code === 'personal_photo';
            $relative = $withPortrait && $isPhoto
                ? $this->writeJpeg('application_documents/portrait-'.Str::random(8).'.jpg')
                : 'application_documents/test.pdf';

            ApplicationDocument::query()->create([
                'application_id' => $application->id,
                'required_document_id' => $rd->id,
                'file_path' => $relative,
                'original_name' => $isPhoto && $withPortrait ? 'portrait.jpg' : 'test.pdf',
                'mime_type' => $isPhoto && $withPortrait ? 'image/jpeg' : 'application/pdf',
                'size' => 120,
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

    private function writeJpeg(string $relative): string
    {
        $full = Storage::disk('local')->path($relative);
        $dir = dirname($full);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $image = imagecreatetruecolor(48, 64);
        $fill = imagecolorallocate($image, 5, 66, 57);
        imagefilledrectangle($image, 0, 0, 47, 63, $fill);
        imagejpeg($image, $full, 80);
        imagedestroy($image);

        return $relative;
    }
}
