<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\Fee;
use App\Models\Fine;
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
use App\Modules\Applications\Support\ServiceWorkflow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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
            'appointment_slot_id' => \App\Models\AppointmentSlot::query()->value('id'),
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
}
