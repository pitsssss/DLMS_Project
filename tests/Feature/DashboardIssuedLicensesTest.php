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
use Tests\Support\AssertsArabicLabels;
use Tests\TestCase;

class DashboardIssuedLicensesTest extends TestCase
{
    use AssertsArabicLabels;
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

    private function licenseEmployee(): User
    {
        return User::factory()->dashboardEmployee('license_employee')->create();
    }

    /**
     * @return array{0: User, 1: License}
     */
    private function issueLicenseForCitizen(?User $issuer = null): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'name' => 'مواطن الرخص '.Str::random(4),
            'national_id' => '1'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-IL-'.strtoupper(Str::random(6)),
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
            'payment_number' => 'PAY-IL-'.strtoupper(Str::random(8)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fine_id' => null,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Completed,
            'provider' => 'mock',
            'provider_reference' => 'mock-il',
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

        $issuer = $issuer ?? $this->licenseEmployee();
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
        $licenseId = (int) $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertOk()
            ->json('data.id');

        return [$citizen, License::query()->findOrFail($licenseId)];
    }

    public function test_authorized_employee_lists_licenses_with_arabic_labels(): void
    {
        [$citizen, $license] = $this->issueLicenseForCitizen();
        Sanctum::actingAs($this->licenseEmployee());

        $data = $this->getJson('/api/dashboard/licenses')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['items']);
        $this->assertNoRawTranslationKeys($data);
        $row = collect($data['items'])->firstWhere('id', $license->id);
        $this->assertNotNull($row);
        $this->assertSame('active', $row['status']);
        $this->assertSame('فعالة', $row['status_label']);
        $this->assertSame($license->license_number, $row['license_number']);
        $this->assertArrayHasKey('actions', $row);
        $this->assertTrue($row['actions']['can_view']);
        $this->assertTrue($row['actions']['can_print']);
    }

    public function test_unauthorized_employee_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('fines_employee')->create());
        // fines_employee has view_licenses actually - use reports_employee
        Sanctum::actingAs(User::factory()->dashboardEmployee('reports_employee')->create());
        $this->getJson('/api/dashboard/licenses')->assertForbidden();
    }

    public function test_search_filters_and_stats(): void
    {
        [$citizen, $license] = $this->issueLicenseForCitizen();
        $employee = $this->licenseEmployee();
        Sanctum::actingAs($employee);

        $this->getJson('/api/dashboard/licenses?search='.urlencode($license->license_number))
            ->assertOk()
            ->assertJsonPath('data.items.0.license_number', $license->license_number);

        $this->getJson('/api/dashboard/licenses?search='.urlencode($citizen->name))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $this->getJson('/api/dashboard/licenses?search='.$citizen->national_id)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        Sanctum::actingAs($employee);
        $this->getJson('/api/dashboard/licenses?status=active')->assertOk();
        $this->getJson('/api/dashboard/licenses?license_type_code=private')->assertOk();
        $this->getJson('/api/dashboard/licenses?service_type_code=new_license')->assertOk();
        $this->getJson('/api/dashboard/licenses?expiry_filter=active')->assertOk();

        $stats = $this->getJson('/api/dashboard/licenses/stats')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, $stats['total']);
        $this->assertGreaterThanOrEqual(1, $stats['active']);

        $options = $this->getJson('/api/dashboard/licenses/options')->assertOk()->json('data');
        $this->assertNoRawTranslationKeys($options);
        $this->assertNotEmpty($options['statuses']);
        $this->assertSame('فعالة', collect($options['statuses'])->firstWhere('value', 'active')['label']);
    }

    public function test_past_expiry_active_is_treated_as_expired_in_list_and_stats(): void
    {
        [, $license] = $this->issueLicenseForCitizen();
        $license->update([
            'expiry_date' => now()->subDay()->toDateString(),
            'status' => LicenseStatus::Active,
        ]);

        Sanctum::actingAs($this->licenseEmployee());
        $row = collect($this->getJson('/api/dashboard/licenses')->json('data.items'))
            ->firstWhere('id', $license->id);
        $this->assertSame('expired', $row['status']);
        $this->assertSame('منتهية الصلاحية', $row['status_label']);

        $stats = $this->getJson('/api/dashboard/licenses/stats')->json('data');
        $this->assertGreaterThanOrEqual(1, $stats['expired']);
    }

    public function test_details_history_block_unblock_and_audit(): void
    {
        [$citizen, $license] = $this->issueLicenseForCitizen();
        $employee = $this->licenseEmployee();
        Sanctum::actingAs($employee);

        $details = $this->getJson("/api/dashboard/licenses/{$license->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($license->license_number, $details['license_number']);
        $this->assertNotNull($details['issued_by']['id']);
        $this->assertNotEmpty($details['digital_license']['verification_url']);
        $this->assertTrue($details['actions']['can_block']);

        $this->getJson("/api/dashboard/licenses/{$license->id}/history")
            ->assertOk()
            ->assertJsonPath('data.items.0.action', 'issued');

        $this->postJson("/api/dashboard/licenses/{$license->id}/block", [])
            ->assertStatus(422);

        $this->postJson("/api/dashboard/licenses/{$license->id}/block", [
            'reason' => 'تحقيق إداري',
        ])->assertOk()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.block.reason', 'تحقيق إداري');

        $license->refresh();
        $this->assertSame('تحقيق إداري', $license->block_reason);
        $this->assertNotNull($license->blocked_at);
        $this->assertSame($employee->id, $license->blocked_by);

        $this->postJson("/api/dashboard/licenses/{$license->id}/unblock")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        Sanctum::actingAs(User::factory()->dashboardEmployee('audit_employee')->create());
        $this->getJson("/api/dashboard/licenses/{$license->id}/audit-logs")->assertForbidden();

        $admin = User::factory()->dashboardAdmin()->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/dashboard/licenses/{$license->id}/audit-logs")
            ->assertOk()
            ->assertJsonStructure(['data' => ['items']]);
    }

    public function test_unblock_after_expiry_returns_expired(): void
    {
        [, $license] = $this->issueLicenseForCitizen();
        $employee = $this->licenseEmployee();
        Sanctum::actingAs($employee);

        $this->postJson("/api/dashboard/licenses/{$license->id}/block", [
            'reason' => 'سبب مؤقت للحظر',
        ])->assertOk();

        $license->update(['expiry_date' => now()->subDays(2)->toDateString()]);

        $this->postJson("/api/dashboard/licenses/{$license->id}/unblock")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_renewal_lineage_from_issuance_and_citizen_renew(): void
    {
        [$citizen, $license] = $this->issueLicenseForCitizen();
        $license->update([
            'expiry_date' => now()->subDay()->toDateString(),
            'status' => LicenseStatus::Expired,
        ]);

        Sanctum::actingAs($citizen);
        $newId = (int) $this->postJson("/api/licenses/{$license->id}/renew")
            ->assertOk()
            ->json('data.id');

        $new = License::query()->findOrFail($newId);
        $this->assertSame($license->id, $new->previous_license_id);
        $this->assertSame(LicenseStatus::Renewed, $license->fresh()->status);
        $this->assertNotNull($new->verification_token);
    }

    public function test_null_issued_by_shows_unavailable(): void
    {
        [, $license] = $this->issueLicenseForCitizen();
        $license->update(['issued_by' => null]);

        Sanctum::actingAs($this->licenseEmployee());
        $this->getJson("/api/dashboard/licenses/{$license->id}")
            ->assertOk()
            ->assertJsonPath('data.issued_by.name', 'غير متوفر');
    }
}
