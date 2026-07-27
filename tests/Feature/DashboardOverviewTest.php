<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\AppointmentSlot;
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
use App\Modules\Licenses\Services\LicenseIssuanceEligibilityService;
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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
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
        // Freeze the absolute instant corresponding to 14:00 Asia/Damascus.
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

    private function asAdmin(): User
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeApplication(array $overrides = []): LicenseApplication
    {
        $citizen = $overrides['citizen'] ?? User::factory()->withApprovedProfile()->create([
            'user_type' => UserType::Citizen,
            'email_verified_at' => now(),
        ]);
        unset($overrides['citizen']);

        $createdAt = $overrides['created_at'] ?? null;
        $updatedAt = $overrides['updated_at'] ?? null;
        unset($overrides['created_at'], $overrides['updated_at']);

        $application = LicenseApplication::query()->create(array_merge([
            'application_number' => 'APP-OV-'.uniqid(),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::Draft,
        ], $overrides));

        if ($createdAt !== null) {
            $createdAtUtc = CarbonImmutable::parse($createdAt)->utc();
            $updatedAtUtc = CarbonImmutable::parse($updatedAt ?? $createdAt)->utc();
            $application->forceFill([
                'created_at' => $createdAtUtc,
                'updated_at' => $updatedAtUtc,
            ])->saveQuietly();
        }

        return $application->refresh();
    }

    /**
     * Build a new_license application that satisfies LicenseIssuanceEligibilityService.
     */
    private function makeIssuableApplication(array $overrides = []): LicenseApplication
    {
        $citizen = $overrides['citizen'] ?? User::factory()->withApprovedProfile()->create([
            'user_type' => UserType::Citizen,
            'email_verified_at' => now(),
        ]);
        unset($overrides['citizen']);

        $skipPayment = (bool) ($overrides['skip_payment'] ?? false);
        $skipDocuments = (bool) ($overrides['skip_documents'] ?? false);
        $skipTests = (bool) ($overrides['skip_tests'] ?? false);
        $failOneTest = (bool) ($overrides['fail_one_test'] ?? false);
        unset($overrides['skip_payment'], $overrides['skip_documents'], $overrides['skip_tests'], $overrides['fail_one_test']);

        $application = $this->makeApplication(array_merge([
            'citizen' => $citizen,
            'status' => ApplicationStatus::Approved,
            'approved_at' => now(),
            'issued_at' => null,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
        ], $overrides));

        if (! $skipPayment) {
            $feeCode = ServiceWorkflow::feeCode(
                ServiceType::query()->whereKey($application->service_type_id)->value('code')
            );
            $fee = Fee::query()
                ->where('code', $feeCode)
                ->where(function ($q) use ($application): void {
                    $q->where(function ($scoped) use ($application): void {
                        $scoped->where('license_type_id', $application->license_type_id)
                            ->where('service_type_id', $application->service_type_id);
                    })->orWhere(function ($scoped) use ($application): void {
                        $scoped->whereNull('license_type_id')
                            ->where('service_type_id', $application->service_type_id);
                    });
                })
                ->orderByRaw('license_type_id IS NULL')
                ->firstOrFail();

            Payment::query()->create([
                'payment_number' => 'PAY-OV-'.strtoupper(Str::random(8)),
                'user_id' => $citizen->id,
                'application_id' => $application->id,
                'fee_id' => $fee->id,
                'amount' => $fee->amount,
                'currency' => $fee->currency,
                'status' => PaymentStatus::Completed,
                'provider' => 'mock',
                'paid_at' => now(),
            ]);
        }

        if (! $skipDocuments) {
            $requiredDocs = RequiredDocument::query()
                ->where('is_active', true)
                ->where('is_required', true)
                ->where(function ($q) use ($application): void {
                    $q->whereNull('license_type_id')->orWhere('license_type_id', $application->license_type_id);
                })
                ->where(function ($q) use ($application): void {
                    $q->whereNull('service_type_id')->orWhere('service_type_id', $application->service_type_id);
                })
                ->get();

            foreach ($requiredDocs as $rd) {
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
        }

        if (! $skipTests) {
            foreach (TestType::query()->where('is_required', true)->where('is_active', true)->orderBy('sequence_order')->get() as $index => $testType) {
                $slot = AppointmentSlot::query()->where('test_type_id', $testType->id)->first()
                    ?? AppointmentSlot::query()->firstOrFail();

                $appointment = TestAppointment::query()->create([
                    'application_id' => $application->id,
                    'citizen_id' => $citizen->id,
                    'appointment_slot_id' => $slot->id,
                    'test_type_id' => $testType->id,
                    'status' => AppointmentStatus::Completed,
                    'scheduled_at' => now()->subDays(3 - $index),
                ]);

                $result = ($failOneTest && $index === 0)
                    ? TestResultStatus::Failed
                    : TestResultStatus::Passed;

                TestResult::query()->create([
                    'application_id' => $application->id,
                    'test_appointment_id' => $appointment->id,
                    'test_type_id' => $testType->id,
                    'result' => $result,
                    'attempt_number' => 1,
                    'recorded_by' => User::factory()->dashboardEmployee('test_employee')->create()->id,
                    'recorded_at' => now()->subDays(2),
                ]);
            }
        }

        return $application->fresh(['serviceType', 'licenseType']);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/dashboard/overview')->assertUnauthorized();
    }

    public function test_citizen_returns_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['user_type' => UserType::Citizen]));

        $this->getJson('/api/dashboard/overview')->assertForbidden();
    }

    public function test_admin_can_access_overview_envelope(): void
    {
        $this->asAdmin();

        $this->getJson('/api/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم جلب بيانات الصفحة الرئيسية بنجاح.')
            ->assertJsonStructure([
                'data' => [
                    'meta' => ['period', 'date_from', 'date_to', 'previous_date_from', 'previous_date_to', 'trend_granularity', 'timezone', 'generated_at'],
                    'visibility',
                    'kpis',
                    'operational_queues',
                    'charts',
                    'recent_applications',
                    'upcoming_appointments',
                    'recent_activities',
                ],
            ])
            ->assertJsonPath('data.meta.period', '30d')
            ->assertJsonPath('data.meta.trend_granularity', 'day')
            ->assertJsonPath('data.meta.timezone', 'Asia/Damascus');
    }

    public function test_period_validation_and_granularity(): void
    {
        $this->asAdmin();

        foreach (['7d', '30d', '90d'] as $period) {
            $this->getJson('/api/dashboard/overview?period='.$period)
                ->assertOk()
                ->assertJsonPath('data.meta.period', $period)
                ->assertJsonPath('data.meta.trend_granularity', 'day');
        }

        $this->getJson('/api/dashboard/overview?period=12m')
            ->assertOk()
            ->assertJsonPath('data.meta.trend_granularity', 'month');

        $this->getJson('/api/dashboard/overview?period=bad')->assertStatus(422);
        $this->getJson('/api/dashboard/overview?recent_limit=0')->assertStatus(422);
        $this->getJson('/api/dashboard/overview?recent_limit=11')->assertStatus(422);
        $this->getJson('/api/dashboard/overview?activity_limit=0')->assertStatus(422);
        $this->getJson('/api/dashboard/overview?activity_limit=11')->assertStatus(422);
    }

    public function test_unauthorized_sections_are_null_for_limited_employee(): void
    {
        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        Sanctum::actingAs($reviewer);

        $response = $this->getJson('/api/dashboard/overview')->assertOk();

        // profile_document_reviewer has review_profiles/review_documents/view_applications — not employees/payments/fines/audit
        $this->assertTrue($response->json('data.visibility.applications'));
        $this->assertNull($response->json('data.kpis.employees'));
        $this->assertNull($response->json('data.kpis.payments'));
        $this->assertNull($response->json('data.kpis.fines'));
        $this->assertNull($response->json('data.recent_activities'));
        $this->assertFalse($response->json('data.visibility.employees'));
    }

    public function test_empty_database_returns_zeros(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/dashboard/overview')->assertOk();

        $this->assertSame(0, $response->json('data.kpis.applications.total'));
        $this->assertSame([], $response->json('data.recent_applications'));
        $this->assertSame('day', $response->json('data.charts.applications_trend.granularity'));
        $this->assertCount(30, $response->json('data.charts.applications_trend.items'));
        $this->assertTrue(collect($response->json('data.charts.applications_trend.items'))->every(fn ($i) => $i['count'] === 0));
    }

    public function test_application_kpis_and_comparison(): void
    {
        $this->asAdmin();

        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-07-20 10:00:00', 'Asia/Damascus'),
            'status' => ApplicationStatus::DocumentsUnderReview,
        ]);
        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-06-10 10:00:00', 'Asia/Damascus'),
        ]);
        $this->makeApplication([
            'status' => ApplicationStatus::Approved,
            'approved_at' => CarbonImmutable::parse('2026-07-22 10:00:00', 'Asia/Damascus'),
            'created_at' => CarbonImmutable::parse('2026-07-01 10:00:00', 'Asia/Damascus'),
        ]);

        $kpi = $this->getJson('/api/dashboard/overview')->json('data.kpis.applications');

        $this->assertSame(3, $kpi['total']);
        $this->assertSame(2, $kpi['current_period']);
        $this->assertSame(1, $kpi['previous_period']);
        $this->assertEquals(100.0, $kpi['change_percentage']);
        $this->assertSame('up', $kpi['trend']);
        $this->assertGreaterThanOrEqual(2, $kpi['pending_action']); // documents_under_review + approved
        $this->assertSame(1, $kpi['approved_current_period']);
    }

    public function test_divide_by_zero_comparison_is_safe(): void
    {
        $this->asAdmin();
        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-07-20 10:00:00', 'Asia/Damascus'),
        ]);

        $kpi = $this->getJson('/api/dashboard/overview')->json('data.kpis.applications');

        $this->assertSame(1, $kpi['current_period']);
        $this->assertSame(0, $kpi['previous_period']);
        $this->assertNull($kpi['change_percentage']);
        $this->assertSame('not_comparable', $kpi['trend']);
    }

    public function test_citizen_and_employee_kpis(): void
    {
        $this->asAdmin();

        User::factory()->create([
            'user_type' => UserType::Citizen,
            'is_active' => true,
            'profile_completed' => true,
            'created_at' => CarbonImmutable::parse('2026-07-20', 'Asia/Damascus'),
        ]);
        User::factory()->create([
            'user_type' => UserType::Citizen,
            'is_active' => false,
            'profile_completed' => false,
            'created_at' => CarbonImmutable::parse('2026-05-01', 'Asia/Damascus'),
        ]);
        User::factory()->dashboardEmployee('fines_employee')->create(['is_active' => true]);
        User::factory()->dashboardEmployee('audit_employee')->create(['is_active' => false]);

        $data = $this->getJson('/api/dashboard/overview')->json('data.kpis');

        $this->assertSame(2, $data['citizens']['total']);
        $this->assertSame(1, $data['citizens']['active']);
        $this->assertSame(1, $data['citizens']['inactive']);
        $this->assertSame(1, $data['citizens']['complete_profiles']);
        $this->assertSame(1, $data['citizens']['registered_current_period']);
        $this->assertGreaterThanOrEqual(3, $data['employees']['total']); // admin + 2 employees
        $this->assertGreaterThanOrEqual(1, $data['employees']['inactive']);
    }

    public function test_payments_licenses_fines_and_queues(): void
    {
        $this->asAdmin();
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $app = $this->makeApplication([
            'citizen' => $citizen,
            'status' => ApplicationStatus::Approved,
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-1',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'amount' => 1000,
            'currency' => 'SYP',
            'status' => PaymentStatus::Completed,
            'paid_at' => CarbonImmutable::parse('2026-07-20 12:00:00', 'Asia/Damascus'),
        ]);
        Payment::query()->create([
            'payment_number' => 'PAY-2',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'amount' => 500,
            'currency' => 'SYP',
            'status' => PaymentStatus::Pending,
        ]);

        License::query()->create([
            'license_number' => 'LIC-OV-1',
            'citizen_id' => $citizen->id,
            'license_type_id' => $app->license_type_id,
            'application_id' => $app->id,
            'status' => 'active',
            'issue_date' => '2026-07-18',
            'expiry_date' => '2031-07-18',
        ]);

        Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 250.5,
            'reason' => 'مخالفة',
            'status' => FineStatus::Unpaid,
        ]);

        $required = RequiredDocument::query()->first()
            ?? RequiredDocument::query()->create([
                'name' => 'هوية',
                'code' => 'id_card_ov',
                'license_type_id' => $app->license_type_id,
                'service_type_id' => $app->service_type_id,
                'is_required' => true,
                'is_active' => true,
            ]);

        $reviewApp = $this->makeApplication(['status' => ApplicationStatus::DocumentsUnderReview]);
        ApplicationDocument::query()->create([
            'application_id' => $reviewApp->id,
            'required_document_id' => $required->id,
            'file_path' => 'docs/a.pdf',
            'original_name' => 'a.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'status' => DocumentStatus::PendingReview,
        ]);

        $data = $this->getJson('/api/dashboard/overview')->json('data');

        $this->assertSame(1, $data['kpis']['payments']['paid_count_current_period']);
        $this->assertSame('1000.00', $data['kpis']['payments']['paid_amount_current_period']);
        $this->assertSame(1, $data['kpis']['payments']['pending_count']);
        $this->assertSame(1, $data['kpis']['licenses']['issued_current_period']);
        $this->assertSame(1, $data['kpis']['fines']['unpaid_count']);
        $this->assertSame('250.50', $data['kpis']['fines']['unpaid_amount']);
        $this->assertSame(1, $data['operational_queues']['payments_pending']);
        $this->assertSame(1, $data['operational_queues']['documents_pending_review']);
        // Approved alone + already-issued license + unpaid fine must not count as ready.
        $this->assertSame(0, $data['operational_queues']['licenses_ready_for_issuance']);
    }

    public function test_appointments_and_tests_kpis(): void
    {
        $this->asAdmin();
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $app = $this->makeApplication(['citizen' => $citizen, 'status' => ApplicationStatus::InTesting]);
        $testType = TestType::query()->firstOrFail();

        $slot = AppointmentSlot::query()->firstOrFail();

        $today = TestAppointment::query()->create([
            'application_id' => $app->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => CarbonImmutable::parse('2026-07-25 10:00:00', 'Asia/Damascus'),
        ]);
        TestAppointment::query()->create([
            'application_id' => $app->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Cancelled,
            'scheduled_at' => CarbonImmutable::parse('2026-07-26 10:00:00', 'Asia/Damascus'),
            'cancelled_at' => now(),
        ]);

        TestResult::query()->create([
            'application_id' => $app->id,
            'test_appointment_id' => $today->id,
            'test_type_id' => $testType->id,
            'result' => TestResultStatus::Passed,
            'attempt_number' => 1,
            'recorded_by' => User::factory()->dashboardEmployee('test_employee')->create()->id,
            'recorded_at' => CarbonImmutable::parse('2026-07-20 12:00:00', 'Asia/Damascus'),
        ]);

        // awaiting: booked without result
        TestAppointment::query()->create([
            'application_id' => $app->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => CarbonImmutable::parse('2026-07-28 10:00:00', 'Asia/Damascus'),
        ]);

        $data = $this->getJson('/api/dashboard/overview')->json('data');

        $this->assertSame(1, $data['kpis']['appointments']['today']);
        $this->assertGreaterThanOrEqual(2, $data['kpis']['appointments']['upcoming_7_days']);
        $this->assertSame(1, $data['kpis']['tests']['passed_current_period']);
        $this->assertEquals(100.0, $data['kpis']['tests']['pass_rate']);
        $this->assertSame(1, $data['kpis']['tests']['awaiting_result']);
        $this->assertNotEmpty($data['upcoming_appointments']);
        $this->assertArrayNotHasKey('phone', $data['upcoming_appointments'][0]['citizen'] ?? []);
    }

    public function test_charts_trend_and_status_distribution(): void
    {
        $this->asAdmin();
        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-07-24 08:00:00', 'Asia/Damascus'),
            'status' => ApplicationStatus::Draft,
        ]);
        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-07-25 08:00:00', 'Asia/Damascus'),
            'status' => ApplicationStatus::PaymentPending,
        ]);

        $charts = $this->getJson('/api/dashboard/overview?period=7d')->json('data.charts');

        $this->assertCount(7, $charts['applications_trend']['items']);
        $buckets = collect($charts['applications_trend']['items'])->pluck('bucket')->all();
        $sorted = $buckets;
        sort($sorted);
        $this->assertSame($sorted, $buckets);

        $day24 = collect($charts['applications_trend']['items'])->firstWhere('bucket', '2026-07-24');
        $this->assertSame(1, $day24['count']);

        $statuses = collect($charts['application_status_distribution'])->keyBy('status');
        $this->assertTrue($statuses->has('draft'));
        $this->assertNotSame('draft', $statuses['draft']['label']);
        $this->assertEquals(100.0, collect($charts['application_status_distribution'])->sum('percentage'));
    }

    public function test_recent_applications_privacy_and_limits(): void
    {
        $this->asAdmin();
        for ($i = 0; $i < 6; $i++) {
            $this->makeApplication([
                'application_number' => 'APP-PRIV-'.$i,
                'created_at' => CarbonImmutable::parse('2026-07-2'.$i.' 10:00:00', 'Asia/Damascus'),
            ]);
        }

        $items = $this->getJson('/api/dashboard/overview?recent_limit=3')
            ->assertOk()
            ->json('data.recent_applications');

        $this->assertCount(3, $items);
        $this->assertArrayHasKey('application_number', $items[0]);
        $this->assertArrayHasKey('name', $items[0]['citizen']);
        $this->assertArrayNotHasKey('national_id', $items[0]['citizen']);
        $this->assertArrayNotHasKey('email', $items[0]['citizen']);
        $this->assertArrayNotHasKey('phone', $items[0]['citizen']);
    }

    public function test_recent_activities_privacy_and_permission(): void
    {
        $admin = $this->asAdmin();
        AuditLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'employee.updated',
            'entity_type' => 'user',
            'entity_id' => $admin->id,
            'old_values' => ['is_active' => true],
            'new_values' => ['is_active' => false],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $items = $this->getJson('/api/dashboard/overview?activity_limit=5')
            ->assertOk()
            ->json('data.recent_activities');

        $this->assertNotEmpty($items);
        $this->assertArrayNotHasKey('ip_address', $items[0]);
        $this->assertArrayNotHasKey('user_agent', $items[0]);
        $this->assertArrayNotHasKey('old_values', $items[0]);
        $this->assertArrayNotHasKey('new_values', $items[0]);
        $this->assertSame('تعديل موظف', $items[0]['action_label']);
    }

    public function test_date_ranges_for_30d_are_correct(): void
    {
        $this->asAdmin();

        $meta = $this->getJson('/api/dashboard/overview?period=30d')->json('data.meta');

        $this->assertSame(config('dlms.business_timezone'), $meta['timezone']);
        $this->assertSame('Asia/Damascus', $meta['timezone']);
        $this->assertNotSame(config('app.timezone'), $meta['timezone']);
        $this->assertStringStartsWith('2026-06-26', $meta['date_from']);
        $this->assertStringStartsWith('2026-07-25', $meta['date_to']);
        $this->assertStringStartsWith('2026-05-27', $meta['previous_date_from']);
        $this->assertStringStartsWith('2026-06-25', $meta['previous_date_to']);
        $this->assertStringContainsString('+03:00', $meta['generated_at']);
        $this->assertTrue(
            CarbonImmutable::parse($meta['previous_date_to'])->lt(CarbonImmutable::parse($meta['date_from']))
        );
    }

    public function test_meta_timezone_uses_business_timezone_not_app_timezone(): void
    {
        $this->asAdmin();
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Damascus', config('dlms.business_timezone'));
        date_default_timezone_set('UTC');

        $meta = $this->getJson('/api/dashboard/overview')->assertOk()->json('data.meta');

        $this->assertSame('Asia/Damascus', $meta['timezone']);
        $this->assertStringContainsString('+03:00', $meta['generated_at']);
        $this->assertStringContainsString('+03:00', $meta['date_from']);
    }

    public function test_utc_stored_timestamps_map_to_damascus_day_buckets(): void
    {
        $this->asAdmin();

        // 21:30 UTC = 00:30 next Damascus day (2026-07-25).
        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-07-24 21:30:00', 'UTC'),
        ]);
        // 20:30 UTC = 23:30 Damascus same calendar day (2026-07-24).
        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-07-24 20:30:00', 'UTC'),
        ]);

        $charts = $this->getJson('/api/dashboard/overview?period=7d')->json('data.charts');
        $day25 = collect($charts['applications_trend']['items'])->firstWhere('bucket', '2026-07-25');
        $day24 = collect($charts['applications_trend']['items'])->firstWhere('bucket', '2026-07-24');

        $this->assertSame(1, $day25['count']);
        $this->assertSame(1, $day24['count']);
    }

    public function test_today_appointments_use_damascus_calendar_date(): void
    {
        $this->asAdmin();
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $app = $this->makeApplication(['citizen' => $citizen]);
        $testType = TestType::query()->firstOrFail();
        $slot = AppointmentSlot::query()->firstOrFail();

        TestAppointment::query()->create([
            'application_id' => $app->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Booked,
            // 00:15 Damascus = 21:15 previous UTC day → still Damascus "today".
            'scheduled_at' => CarbonImmutable::parse('2026-07-25 00:15:00', 'Asia/Damascus')->utc(),
        ]);
        TestAppointment::query()->create([
            'application_id' => $app->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Booked,
            // 23:45 previous Damascus day = 20:45 UTC → previous business day.
            'scheduled_at' => CarbonImmutable::parse('2026-07-24 23:45:00', 'Asia/Damascus')->utc(),
        ]);

        $kpi = $this->getJson('/api/dashboard/overview')->json('data.kpis.appointments');

        $this->assertSame(1, $kpi['today']);
    }

    public function test_12m_monthly_buckets_use_business_timezone(): void
    {
        $this->asAdmin();
        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-07-01 00:30:00', 'Asia/Damascus')->utc(),
        ]);
        $this->makeApplication([
            'created_at' => CarbonImmutable::parse('2026-06-30 23:30:00', 'Asia/Damascus')->utc(),
        ]);

        $trend = $this->getJson('/api/dashboard/overview?period=12m')->json('data.charts.applications_trend');

        $this->assertSame('month', $trend['granularity']);
        $this->assertCount(12, $trend['items']);
        $this->assertSame(1, collect($trend['items'])->firstWhere('bucket', '2026-07')['count']);
        $this->assertSame(1, collect($trend['items'])->firstWhere('bucket', '2026-06')['count']);
    }

    public function test_approved_alone_is_not_ready_for_issuance(): void
    {
        $this->asAdmin();
        $this->makeApplication(['status' => ApplicationStatus::Approved, 'approved_at' => now()]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_missing_payment_excludes_ready_for_issuance(): void
    {
        $this->asAdmin();
        $this->makeIssuableApplication(['skip_payment' => true]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_pending_payment_excludes_ready_for_issuance(): void
    {
        $this->asAdmin();
        $application = $this->makeIssuableApplication(['skip_payment' => true]);
        $fee = Fee::query()->where('code', 'application_fee')->firstOrFail();
        Payment::query()->create([
            'payment_number' => 'PAY-PENDING-OV',
            'user_id' => $application->citizen_id,
            'application_id' => $application->id,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Pending,
        ]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_missing_or_rejected_document_excludes_ready_for_issuance(): void
    {
        $this->asAdmin();
        $application = $this->makeIssuableApplication(['skip_documents' => true]);
        $rd = RequiredDocument::query()
            ->where('is_required', true)
            ->where('is_active', true)
            ->where(function ($q) use ($application): void {
                $q->whereNull('license_type_id')->orWhere('license_type_id', $application->license_type_id);
            })
            ->where(function ($q) use ($application): void {
                $q->whereNull('service_type_id')->orWhere('service_type_id', $application->service_type_id);
            })
            ->firstOrFail();

        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'required_document_id' => $rd->id,
            'file_path' => 'docs/r.pdf',
            'original_name' => 'r.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'status' => DocumentStatus::Rejected,
        ]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_missing_or_failed_tests_exclude_ready_for_issuance(): void
    {
        $this->asAdmin();
        $this->makeIssuableApplication(['skip_tests' => true]);
        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));

        $this->makeIssuableApplication(['fail_one_test' => true]);
        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_fully_eligible_application_is_counted_once(): void
    {
        $this->asAdmin();
        $application = $this->makeIssuableApplication();

        $this->assertTrue(app(LicenseIssuanceEligibilityService::class)->isReady($application));
        $this->assertSame(1, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_already_issued_application_is_excluded_from_ready_queue(): void
    {
        $this->asAdmin();
        $application = $this->makeIssuableApplication();
        License::query()->create([
            'license_number' => 'LIC-OV-READY-1',
            'citizen_id' => $application->citizen_id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => '2026-07-20',
            'expiry_date' => '2031-07-20',
        ]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_unpaid_fines_exclude_ready_for_issuance(): void
    {
        $this->asAdmin();
        $application = $this->makeIssuableApplication();
        Fine::query()->create([
            'citizen_id' => $application->citizen_id,
            'amount' => 1000,
            'reason' => 'مخالفة',
            'status' => FineStatus::Unpaid,
        ]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_ready_queue_null_without_license_permission(): void
    {
        $employee = User::factory()->dashboardEmployee('audit_employee')->create();
        Sanctum::actingAs($employee);

        $this->makeIssuableApplication();

        $response = $this->getJson('/api/dashboard/overview')->assertOk();
        $this->assertFalse($response->json('data.visibility.licenses'));
        $this->assertNull($response->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_issuance_endpoint_and_overview_ready_count_stay_consistent(): void
    {
        $this->asAdmin();
        $application = $this->makeIssuableApplication();

        $this->assertSame(1, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));

        $issuer = User::factory()->dashboardEmployee('license_employee')->create();
        Sanctum::actingAs($issuer);

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertOk()
            ->assertJsonPath('data.status', LicenseStatus::Active->value);

        Sanctum::actingAs(User::factory()->dashboardAdmin('super_admin')->create());

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
    }

    public function test_incomplete_application_is_rejected_by_issuance_and_not_counted(): void
    {
        $this->asAdmin();
        $application = $this->makeIssuableApplication(['skip_payment' => true]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));

        $issuer = User::factory()->dashboardEmployee('license_employee')->create();
        Sanctum::actingAs($issuer);

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertStatus(422);
    }

    public function test_license_unblock_is_excluded_from_ready_and_issue_license(): void
    {
        $this->asAdmin();
        $unblock = ServiceType::query()->where('code', 'license_unblock')->firstOrFail();
        $application = $this->makeIssuableApplication([
            'skip_tests' => true,
            'service_type_id' => $unblock->id,
        ]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));

        Sanctum::actingAs(User::factory()->dashboardEmployee('license_employee')->create());
        $before = License::query()->count();
        $this->postJson("/api/admin/applications/{$application->id}/issue-license")->assertStatus(422);
        $this->assertSame($before, License::query()->count());
        $this->assertSame(ApplicationStatus::Approved->value, $application->fresh()->status->value);
    }

    public function test_unknown_custom_service_code_is_not_issuable(): void
    {
        $this->asAdmin();
        $custom = ServiceType::query()->create([
            'name' => 'خدمة مخصصة',
            'code' => 'custom_dashboard_service',
            'description' => 'اختبار',
            'is_active' => true,
        ]);

        $application = $this->makeIssuableApplication([
            'skip_tests' => true,
            'skip_payment' => true,
            'skip_documents' => true,
            'service_type_id' => $custom->id,
        ]);

        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));

        Sanctum::actingAs(User::factory()->dashboardEmployee('license_employee')->create());
        $beforeLicenses = License::query()->count();
        $beforeAudits = AuditLog::query()->where('action', 'license.issued')->count();

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")->assertStatus(422);

        $this->assertSame($beforeLicenses, License::query()->count());
        $this->assertSame($beforeAudits, AuditLog::query()->where('action', 'license.issued')->count());
        $this->assertSame(ApplicationStatus::Approved->value, $application->fresh()->status->value);
    }

    public function test_duplicate_issue_license_is_prevented_after_success(): void
    {
        $this->asAdmin();
        $application = $this->makeIssuableApplication();
        $this->assertSame(1, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));

        $issuer = User::factory()->dashboardEmployee('license_employee')->create();
        Sanctum::actingAs($issuer);
        $this->postJson("/api/admin/applications/{$application->id}/issue-license")->assertOk();
        $this->postJson("/api/admin/applications/{$application->id}/issue-license")->assertStatus(422);

        Sanctum::actingAs(User::factory()->dashboardAdmin('super_admin')->create());
        $this->assertSame(0, $this->getJson('/api/dashboard/overview')->json('data.operational_queues.licenses_ready_for_issuance'));
        $this->assertSame(1, License::query()->where('application_id', $application->id)->count());
    }

    public function test_overview_payment_kpi_returns_decimal_string_amounts(): void
    {
        $this->asAdmin();
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $app = $this->makeApplication([
            'citizen' => $citizen,
            'status' => ApplicationStatus::Approved,
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-OV-PREC-1',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'amount' => '9999999999.99',
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'paid_at' => CarbonImmutable::parse('2026-07-20 12:00:00', 'Asia/Damascus'),
        ]);

        $payments = $this->getJson('/api/dashboard/overview')->assertOk()->json('data.kpis.payments');

        $this->assertIsString($payments['paid_amount_current_period']);
        $this->assertSame('9999999999.99', $payments['paid_amount_current_period']);
        $this->assertSame('USD', $payments['currency']);
    }

    public function test_overview_payment_kpi_separates_multi_currency_amounts(): void
    {
        $this->asAdmin();
        $citizen = User::factory()->withApprovedProfile()->create(['user_type' => UserType::Citizen]);
        $app = $this->makeApplication([
            'citizen' => $citizen,
            'status' => ApplicationStatus::Approved,
        ]);
        $paidAt = CarbonImmutable::parse('2026-07-20 12:00:00', 'Asia/Damascus');

        Payment::query()->create([
            'payment_number' => 'PAY-OV-MC-SYP',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'amount' => '1000.00',
            'currency' => 'SYP',
            'status' => PaymentStatus::Completed,
            'paid_at' => $paidAt,
        ]);

        Payment::query()->create([
            'payment_number' => 'PAY-OV-MC-USD',
            'user_id' => $citizen->id,
            'application_id' => $app->id,
            'amount' => '10.25',
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'paid_at' => $paidAt,
        ]);

        $payments = $this->getJson('/api/dashboard/overview')->assertOk()->json('data.kpis.payments');

        $this->assertNull($payments['paid_amount_current_period']);
        $this->assertNull($payments['currency']);
        $this->assertSame('1000.00', $payments['paid_amount_by_currency_current_period']['SYP']);
        $this->assertSame('10.25', $payments['paid_amount_by_currency_current_period']['USD']);
        $this->assertSame('not_comparable', $payments['trend']);
    }
}
