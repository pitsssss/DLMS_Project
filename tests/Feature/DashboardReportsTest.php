<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\AppointmentSlot;
use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\DevelopmentDemoSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardReportsTest extends TestCase
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
        config([
            'app.timezone' => 'UTC',
            'dlms.business_timezone' => 'Asia/Damascus',
        ]);
        date_default_timezone_set('UTC');
        $frozen = CarbonImmutable::parse('2026-07-25 14:00:00', 'Asia/Damascus');
        CarbonImmutable::setTestNow($frozen);
        Carbon::setTestNow($frozen);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reports_require_view_reports_permission(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('license_employee')->create());

        $this->getJson('/api/dashboard/reports/summary')->assertForbidden();
    }

    public function test_reports_summary_respects_section_visibility(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('reports_employee')->create());

        $response = $this->getJson('/api/dashboard/reports/summary?period=30d')->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('visibility', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertSame('Asia/Damascus', $data['meta']['timezone']);
        $this->assertArrayNotHasKey('applications', $data['summary']);
        $this->assertArrayNotHasKey('application_payments', $data['summary']);
    }

    public function test_custom_period_validation_and_max_range(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());

        $this->getJson('/api/dashboard/reports/summary?period=custom&date_from=2026-01-01')
            ->assertUnprocessable();

        $this->getJson('/api/dashboard/reports/summary?period=custom&date_from=2024-01-01&date_to=2026-07-25')
            ->assertStatus(422);
    }

    public function test_business_timezone_boundaries_for_applications(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());

        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $licenseTypeId = LicenseType::query()->where('code', 'private')->value('id');
        $serviceTypeId = ServiceType::query()->where('code', 'new_license')->value('id');

        $inside = LicenseApplication::query()->create([
            'application_number' => 'APP-RPT-IN',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'status' => ApplicationStatus::Draft,
        ]);
        $inside->forceFill([
            'created_at' => CarbonImmutable::parse('2026-07-25 20:30:00', 'UTC'),
        ])->saveQuietly();

        $outside = LicenseApplication::query()->create([
            'application_number' => 'APP-RPT-OUT',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'status' => ApplicationStatus::Draft,
        ]);
        $outside->forceFill([
            'created_at' => CarbonImmutable::parse('2026-07-24 20:30:00', 'UTC'),
        ])->saveQuietly();

        $data = $this->getJson('/api/dashboard/reports/applications?period=custom&date_from=2026-07-25&date_to=2026-07-25')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['summary']['submitted']);
        $this->assertSame('day', $data['meta']['group_by']);
    }

    public function test_application_report_rates_and_pagination(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $licenseTypeId = LicenseType::query()->where('code', 'private')->value('id');
        $serviceTypeId = ServiceType::query()->where('code', 'new_license')->value('id');

        foreach (['approved', 'rejected'] as $status) {
            LicenseApplication::query()->create([
                'application_number' => 'APP-RPT-'.strtoupper($status),
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseTypeId,
                'service_type_id' => $serviceTypeId,
                'status' => $status === 'approved' ? ApplicationStatus::Approved : ApplicationStatus::Rejected,
                'submitted_at' => now(),
                'approved_at' => $status === 'approved' ? now() : null,
            ]);
        }

        $data = $this->getJson('/api/dashboard/reports/applications?period=30d&per_page=1&page=1')
            ->assertOk()
            ->json('data');

        $this->assertEquals(50.0, $data['summary']['approval_rate']);
        $this->assertEquals(50.0, $data['summary']['rejection_rate']);
        $this->assertCount(1, $data['rows']);
        $this->assertSame(1, $data['pagination']['per_page']);
        $this->assertSame(2, $data['pagination']['total']);
    }

    public function test_tests_report_counts_and_zero_denominator_rates(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $data = $this->getJson('/api/dashboard/reports/tests?period=30d')->assertOk()->json('data');
        $this->assertNull($data['summary']['pass_rate']);
        $this->assertSame(0, $data['summary']['total_recorded']);
    }

    public function test_fines_report_separate_from_application_payments(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $app = LicenseApplication::query()->create([
            'application_number' => 'APP-RPT-FINE',
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::Approved,
        ]);

        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 100,
            'reason' => 'speeding',
            'status' => FineStatus::Paid,
            'paid_at' => now(),
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-FINE-LINK',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'fine_id' => $fine->id,
            'amount' => 100,
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-APP-ONLY',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'amount' => 50,
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $summary = $this->getJson('/api/dashboard/reports/summary?period=30d')->assertOk()->json('data.summary');
        $this->assertSame(1, $summary['application_payments']['completed_count']);
        $this->assertSame('50.00', $summary['application_payments']['completed_amount']);

        $fines = $this->getJson('/api/dashboard/reports/fines?period=30d')->assertOk()->json('data.summary');
        $this->assertSame(1, $fines['paid']);
        $this->assertSame('100.00', $fines['paid_amount']);
    }

    public function test_legacy_overview_excludes_fine_linked_payments_and_hides_financials(): void
    {
        $reportsOnly = User::factory()->dashboardEmployee('reports_employee')->create();
        Sanctum::actingAs($reportsOnly);

        $legacy = $this->getJson('/api/admin/reports/overview')->assertOk()->json('data');
        $this->assertNull($legacy['payments']);
        $this->assertNull($legacy['fines']);
        $this->assertTrue($legacy['deprecated']);

        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $app = LicenseApplication::query()->create([
            'application_number' => 'APP-LEG',
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::Approved,
        ]);
        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 10,
            'reason' => 'x',
            'status' => FineStatus::Paid,
        ]);
        Payment::query()->create([
            'payment_number' => 'PAY-L1',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'fine_id' => $fine->id,
            'amount' => 10,
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
        ]);
        Payment::query()->create([
            'payment_number' => 'PAY-L2',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'amount' => 25.50,
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
        ]);

        $legacy = $this->getJson('/api/admin/reports/overview')->assertOk()->json('data.payments');
        $this->assertSame(1, $legacy['completed_count']);
        $this->assertSame('25.50', $legacy['completed_amount']);
    }

    public function test_overview_payments_pending_excludes_fine_linked(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $app = LicenseApplication::query()->create([
            'application_number' => 'APP-OV-PEND',
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::Approved,
        ]);
        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 10,
            'reason' => 'x',
            'status' => FineStatus::Unpaid,
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-PEND-APP',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'amount' => 50,
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
        ]);
        Payment::query()->create([
            'payment_number' => 'PAY-PEND-FINE',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'fine_id' => $fine->id,
            'amount' => 10,
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
        ]);

        $pending = $this->getJson('/api/dashboard/overview')->json('data.operational_queues.payments_pending');
        $this->assertSame(1, $pending);
    }

    public function test_employee_report_privacy_and_metrics(): void
    {
        $admin = User::factory()->dashboardAdmin()->create();
        Sanctum::actingAs($admin);

        $employee = User::factory()->dashboardEmployee('test_employee')->create();
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-EMP',
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::InTesting,
        ]);
        $testType = TestType::query()->first();
        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => AppointmentSlot::query()->value('id'),
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
            'recorded_by' => $employee->id,
            'recorded_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $employee->id,
            'action' => 'license.issued',
            'entity_type' => 'license',
            'entity_id' => 1,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'secret-agent',
        ]);

        $row = collect($this->getJson('/api/dashboard/reports/employees?period=30d')->assertOk()->json('data.rows'))
            ->firstWhere('employee.id', $employee->id);

        $this->assertSame(1, $row['test_results_recorded']);
        $this->assertSame(1, $row['licenses_issued']);
        $this->assertArrayNotHasKey('ip_address', $row);
        $this->assertArrayNotHasKey('user_agent', $row);
    }

    public function test_options_endpoint_returns_labels(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $options = $this->getJson('/api/dashboard/reports/options')->assertOk()->json('data');

        $this->assertNotEmpty($options['periods']);
        $this->assertNotEmpty($options['application_statuses']);
        $this->assertArrayHasKey('value', $options['application_statuses'][0]);
        $this->assertArrayHasKey('label', $options['application_statuses'][0]);
    }

    public function test_domain_report_routes_enforce_permissions(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('reports_employee')->create());
        $this->getJson('/api/dashboard/reports/applications?period=30d')->assertForbidden();
        $this->getJson('/api/dashboard/reports/fines?period=30d')->assertForbidden();
    }

    public function test_summary_contract_exposes_frontend_kpi_keys(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $this->seedReportFixture();

        $data = $this->getJson('/api/dashboard/reports/summary?period=30d')->assertOk()->json('data');

        $this->assertSame(2, $data['summary']['applications_total']);
        $this->assertSame(2, $data['kpis']['applications_total']);
        $this->assertSame(1, $data['summary']['citizens_total']);
        $this->assertSame(1, $data['summary']['tests_total']);
        $this->assertSame(1, $data['summary']['appointments_total']);
        $this->assertSame(1, $data['summary']['licenses_issued']);
        $this->assertSame(1, $data['summary']['fines_total']);
        $this->assertGreaterThanOrEqual(1, $data['summary']['employees_total']);

        $this->assertArrayHasKey('applications', $data['summary']);
        $this->assertSame(2, $data['summary']['applications']['submitted']);
        $this->assertSame(1, $data['summary']['application_payments']['completed_count']);

        $this->assertNotEmpty($data['operational']);
        $this->assertNotEmpty($data['financial']['completed_payments']);
        $this->assertSame('50.00', $data['financial']['completed_payments'][0]['amount']);
    }

    public function test_summary_series_and_breakdowns_are_populated(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $this->seedReportFixture();

        $data = $this->getJson('/api/dashboard/reports/summary?period=30d')->assertOk()->json('data');

        foreach (['applications_created', 'applications_completed', 'licenses_issued', 'tests_recorded'] as $key) {
            $this->assertArrayHasKey($key, $data['series']);
            $this->assertNotEmpty($data['series'][$key]);
            $this->assertArrayHasKey('bucket', $data['series'][$key][0]);
            $this->assertArrayHasKey('count', $data['series'][$key][0]);
            $this->assertArrayHasKey('label', $data['series'][$key][0]);
        }

        $this->assertGreaterThan(0, array_sum(array_column($data['series']['applications_created'], 'count')));
        $this->assertNotEmpty($data['breakdowns']['application_status']);
        $this->assertNotEmpty($data['breakdowns']['status']);
        $this->assertNotEmpty($data['breakdowns']['service_type']);
        $this->assertArrayHasKey('label', $data['breakdowns']['application_status'][0]);
        $this->assertArrayHasKey('count', $data['breakdowns']['application_status'][0]);
        $this->assertNotEmpty($data['breakdowns']['payment_status']);
        $this->assertNotEmpty($data['breakdowns']['fine_status']);
    }

    public function test_reports_employee_does_not_receive_hidden_flat_kpis(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('reports_employee')->create());
        $this->seedReportFixture();

        $data = $this->getJson('/api/dashboard/reports/summary?period=30d')->assertOk()->json('data');

        $this->assertArrayNotHasKey('applications', $data['summary']);
        $this->assertArrayNotHasKey('applications_total', $data['summary']);
        $this->assertArrayNotHasKey('application_payments', $data['summary']);
        $this->assertArrayNotHasKey('applications_created', $data['series']);
        $this->assertSame([], $data['financial']);
        $this->assertFalse($data['visibility']['applications']);
    }

    public function test_empty_database_summary_returns_zero_kpis_and_series_buckets(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());

        $data = $this->getJson('/api/dashboard/reports/summary?period=30d')->assertOk()->json('data');

        $this->assertSame(0, $data['summary']['applications_total']);
        $this->assertSame(0, $data['summary']['tests_total']);
        $this->assertSame(0, $data['summary']['appointments_total']);
        $this->assertSame(0, $data['summary']['licenses_issued']);
        $this->assertSame(0, $data['summary']['fines_total']);
        $this->assertSame(0, $data['kpis']['applications_total']);
        $this->assertCount(30, $data['series']['applications_created']);
        $this->assertSame(0, array_sum(array_column($data['series']['applications_created'], 'count')));
        $this->assertSame([], $data['breakdowns']['application_status'] ?? $data['breakdowns']['status'] ?? []);
    }

    public function test_domain_series_use_named_maps_with_labeled_breakdowns(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $this->seedReportFixture();

        $applications = $this->getJson('/api/dashboard/reports/applications?period=30d')->assertOk()->json('data');
        $this->assertSame(2, $applications['summary']['total']);
        $this->assertSame(2, $applications['summary']['submitted']);
        $this->assertArrayHasKey('created', $applications['series']);
        $this->assertArrayHasKey('count', $applications['series']['created'][0]);
        $this->assertNotEmpty($applications['breakdowns']['status']);
        $this->assertNotEmpty($applications['breakdowns']['by_status']);
        $this->assertArrayHasKey('label', $applications['breakdowns']['status'][0]);
        $this->assertNotEmpty($applications['rows']);
        $this->assertArrayHasKey('processing_duration', $applications['rows'][0]);

        $tests = $this->getJson('/api/dashboard/reports/tests?period=30d')->assertOk()->json('data');
        $this->assertSame(1, $tests['summary']['total_recorded']);
        $this->assertSame(1, $tests['summary']['recorded']);
        $this->assertArrayHasKey('passed', $tests['series']);
        $this->assertNotEmpty($tests['breakdowns']['result']);

        $licenses = $this->getJson('/api/dashboard/reports/licenses?period=30d')->assertOk()->json('data');
        $this->assertSame(1, $licenses['summary']['issued']);
        $this->assertArrayHasKey('issued_at', $licenses['rows'][0]);
        $this->assertArrayHasKey('expires_at', $licenses['rows'][0]);
        $this->assertNotEmpty($licenses['breakdowns']['license_type']);

        $fines = $this->getJson('/api/dashboard/reports/fines?period=30d')->assertOk()->json('data');
        $this->assertSame(1, $fines['summary']['paid']);
        $this->assertNotEmpty($fines['breakdowns']['status']);
        $this->assertNotEmpty($fines['breakdowns']['violation_type']);
    }

    public function test_summary_includes_seeded_demo_counts(): void
    {
        $this->seed(DevelopmentDemoSeeder::class);
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());

        $data = $this->getJson('/api/dashboard/reports/summary?period=30d')->assertOk()->json('data');

        $this->assertGreaterThan(0, $data['summary']['applications_total']);
        $this->assertGreaterThan(0, array_sum(array_column($data['series']['applications_created'], 'count')));
        $this->assertNotEmpty($data['breakdowns']['service_type']);

        $applications = $this->getJson('/api/dashboard/reports/applications?period=30d')->assertOk()->json('data');
        $this->assertGreaterThan(0, $applications['pagination']['total']);
        $this->assertNotEmpty($applications['rows']);
    }

    /**
     * @return array{citizen: User, application: LicenseApplication}
     */
    private function seedReportFixture(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $examiner = User::factory()->dashboardEmployee('test_employee')->create();
        $licenseTypeId = LicenseType::query()->where('code', 'private')->value('id');
        $serviceTypeId = ServiceType::query()->where('code', 'new_license')->value('id');
        $testType = TestType::query()->firstOrFail();
        $slotId = AppointmentSlot::query()->value('id');

        $approved = LicenseApplication::query()->create([
            'application_number' => 'APP-RPT-CONTRACT-A',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'status' => ApplicationStatus::Approved,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);
        $issuedApp = LicenseApplication::query()->create([
            'application_number' => 'APP-RPT-CONTRACT-B',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now(),
            'issued_at' => now(),
        ]);

        License::query()->create([
            'license_number' => 'LIC-RPT-1',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'application_id' => $issuedApp->id,
            'status' => 'active',
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(5)->toDateString(),
        ]);

        $appointment = TestAppointment::query()->create([
            'application_id' => $approved->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slotId,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Completed,
            'scheduled_at' => now(),
        ]);

        TestResult::query()->create([
            'application_id' => $approved->id,
            'test_appointment_id' => $appointment->id,
            'test_type_id' => $testType->id,
            'result' => TestResultStatus::Passed,
            'attempt_number' => 1,
            'recorded_by' => $examiner->id,
            'recorded_at' => now(),
        ]);

        Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 100,
            'reason' => 'speeding',
            'status' => FineStatus::Paid,
            'paid_at' => now(),
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-RPT-APP',
            'user_id' => $citizen->id,
            'application_id' => $approved->id,
            'amount' => 50,
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        return ['citizen' => $citizen, 'application' => $approved];
    }
}
