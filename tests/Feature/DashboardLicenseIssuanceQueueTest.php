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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardLicenseIssuanceQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $frozen = CarbonImmutable::parse('2026-08-14 14:00:00', 'Asia/Damascus');
        CarbonImmutable::setTestNow($frozen);
        Carbon::setTestNow($frozen);
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_license_employee_can_list_ready_applications(): void
    {
        $this->asIssuer();
        $application = $this->makeIssuableApplication([
            'citizen' => User::factory()->withApprovedProfile()->create([
                'user_type' => UserType::Citizen,
                'name' => 'Issuance Citizen',
                'email_verified_at' => now(),
            ]),
        ]);

        $response = $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $application->id)
            ->assertJsonPath('data.items.0.application_number', $application->application_number)
            ->assertJsonPath('data.items.0.status.value', ApplicationStatus::Approved->value)
            ->assertJsonPath('data.items.0.actions.can_issue_license', true)
            ->assertJsonPath('data.items.0.readiness.is_ready', true)
            ->assertJsonPath('data.items.0.readiness.checklist.application_approved', true)
            ->assertJsonPath('data.items.0.readiness.checklist.payment_completed', true)
            ->assertJsonPath('data.items.0.readiness.checklist.documents_approved', true)
            ->assertJsonPath('data.items.0.readiness.checklist.required_tests_passed', true)
            ->assertJsonPath('data.items.0.readiness.checklist.no_unpaid_fines', true)
            ->assertJsonPath('data.items.0.readiness.checklist.not_already_issued', true);

        $this->assertSame(__('messages.licenses.dashboard_issuance_queue_retrieved'), $response->json('message'));

        $item = $response->json('data.items.0');
        $this->assertSame($application->citizen_id, $item['citizen']['id']);
        $this->assertSame('Issuance Citizen', $item['citizen']['name']);
        $this->assertArrayNotHasKey('phone', $item['citizen']);
        $this->assertArrayNotHasKey('national_id', $item['citizen']);
        $this->assertNotEmpty($item['service_type']['id']);
        $this->assertSame('new_license', $item['service_type']['code']);
        $this->assertSame('private', $item['license_type']['code']);
        $this->assertNotEmpty($item['approved_at']);
        $this->assertSame([], $item['readiness']['blockers']);

        $this->getJson("/api/dashboard/license-issuance/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id)
            ->assertJsonPath('data.readiness.is_ready', true)
            ->assertJsonPath('data.actions.can_issue_license', true);
    }

    public function test_approved_but_unpaid_application_is_excluded(): void
    {
        $this->asIssuer();
        $unpaid = $this->makeIssuableApplication(['skip_payment' => true]);
        $ready = $this->makeIssuableApplication();

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $ready->id);

        $this->getJson("/api/dashboard/license-issuance/applications/{$unpaid->id}")
            ->assertOk()
            ->assertJsonPath('data.readiness.is_ready', false)
            ->assertJsonPath('data.readiness.checklist.payment_completed', false)
            ->assertJsonPath('data.actions.can_issue_license', false);

        $blockers = collect($this->getJson("/api/dashboard/license-issuance/applications/{$unpaid->id}")->json('data.readiness.blockers'))
            ->pluck('code')
            ->all();
        $this->assertContains('payment_required', $blockers);
    }

    public function test_missing_required_approved_documents_is_excluded(): void
    {
        $this->asIssuer();
        $this->makeIssuableApplication(['skip_documents' => true]);
        $ready = $this->makeIssuableApplication();

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $ready->id);
    }

    public function test_new_license_application_missing_tests_is_excluded(): void
    {
        $this->asIssuer();
        $this->makeIssuableApplication(['skip_tests' => true]);
        $ready = $this->makeIssuableApplication();

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $ready->id);
    }

    public function test_application_with_unpaid_fine_is_excluded(): void
    {
        $this->asIssuer();
        $withFine = $this->makeIssuableApplication();
        Fine::query()->create([
            'citizen_id' => $withFine->citizen_id,
            'amount' => 1000,
            'reason' => 'مخالفة',
            'status' => FineStatus::Unpaid,
        ]);
        $ready = $this->makeIssuableApplication();

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $ready->id);
    }

    public function test_already_issued_application_is_excluded(): void
    {
        $this->asIssuer();
        $issued = $this->makeIssuableApplication();
        License::query()->create([
            'license_number' => 'LIC-ISSUE-Q-1',
            'citizen_id' => $issued->citizen_id,
            'license_type_id' => $issued->license_type_id,
            'application_id' => $issued->id,
            'status' => LicenseStatus::Active,
            'issue_date' => '2026-08-01',
            'expiry_date' => '2036-08-01',
        ]);
        $ready = $this->makeIssuableApplication();

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $ready->id);
    }

    public function test_license_unblock_is_excluded(): void
    {
        $this->asIssuer();
        $unblock = ServiceType::query()->where('code', 'license_unblock')->firstOrFail();
        $this->makeIssuableApplication([
            'skip_tests' => true,
            'service_type_id' => $unblock->id,
        ]);
        $ready = $this->makeIssuableApplication();

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $ready->id);
    }

    public function test_ready_renew_and_replacement_are_included(): void
    {
        $this->asIssuer();
        $renew = $this->makeReadyFollowOnApplication('renew_license');
        $replacement = $this->makeReadyFollowOnApplication('lost_replacement');

        $response = $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($renew->id, $ids);
        $this->assertContains($replacement->id, $ids);

        $renewItem = collect($response->json('data.items'))->firstWhere('id', $renew->id);
        $this->assertSame('renew_license', $renewItem['service_type']['code']);
        $this->assertNotNull($renewItem['related_license']);
        $this->assertSame($renew->related_license_id, $renewItem['related_license']['id']);
        $this->assertTrue($renewItem['readiness']['checklist']['required_tests_passed']);
        $this->assertTrue($renewItem['readiness']['is_ready']);
        $this->assertTrue($renewItem['actions']['can_issue_license']);
    }

    public function test_can_issue_license_respects_permission(): void
    {
        $application = $this->makeIssuableApplication();

        Sanctum::actingAs(User::factory()->dashboardEmployee('application_manager')->create());

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $application->id)
            ->assertJsonPath('data.items.0.actions.can_issue_license', false)
            ->assertJsonPath('data.items.0.actions.can_view_application', true)
            ->assertJsonPath('data.items.0.readiness.is_ready', true);

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertForbidden();
    }

    public function test_unauthorized_employee_cannot_access(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('test_employee')->create());

        $this->getJson('/api/dashboard/license-issuance/applications')->assertForbidden();
        $this->getJson('/api/dashboard/license-issuance/applications/1')->assertForbidden();
    }

    public function test_citizen_cannot_access(): void
    {
        Sanctum::actingAs(User::factory()->withApprovedProfile()->create([
            'user_type' => UserType::Citizen,
            'email_verified_at' => now(),
        ]));

        $this->getJson('/api/dashboard/license-issuance/applications')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_pagination_search_and_filters(): void
    {
        $this->asIssuer();

        $named = $this->makeIssuableApplication([
            'citizen' => User::factory()->withApprovedProfile()->create([
                'user_type' => UserType::Citizen,
                'name' => 'Unique Issuance Name',
                'email_verified_at' => now(),
            ]),
        ]);
        $public = $this->makeIssuableApplication([
            'license_type_id' => LicenseType::query()->where('code', 'public')->value('id'),
        ]);
        $renew = $this->makeReadyFollowOnApplication('renew_license');
        $older = $this->makeIssuableApplication();
        $older->forceFill(['approved_at' => now()->subDay()])->saveQuietly();

        $this->getJson('/api/dashboard/license-issuance/applications?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 4)
            ->assertJsonPath('data.pagination.last_page', 4);

        $this->getJson('/api/dashboard/license-issuance/applications?search=Unique Issuance Name')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $named->id);

        $this->getJson('/api/dashboard/license-issuance/applications?search='.$named->application_number)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $named->id);

        $this->getJson('/api/dashboard/license-issuance/applications?service_type_code=renew_license')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $renew->id);

        $this->getJson('/api/dashboard/license-issuance/applications?license_type_code=public')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $public->id);

        $this->getJson('/api/dashboard/license-issuance/applications?date=2026-08-14')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3);

        $this->getJson('/api/dashboard/license-issuance/applications?date_from=2026-08-13&date_to=2026-08-13')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $older->id);
    }

    public function test_existing_issue_license_still_succeeds_for_ready_application(): void
    {
        $this->asIssuer();
        $application = $this->makeIssuableApplication();

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.actions.can_issue_license', true);

        $issued = $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertOk()
            ->assertJsonPath('data.status', LicenseStatus::Active->value);

        $this->assertNotNull($issued->json('data.id'));
        $this->assertNotEmpty($issued->json('data.license_number'));

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_stale_condition_causes_issue_license_422_after_ready_get(): void
    {
        $this->asIssuer();
        $application = $this->makeIssuableApplication();

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $application->id)
            ->assertJsonPath('data.items.0.readiness.is_ready', true);

        Fine::query()->create([
            'citizen_id' => $application->citizen_id,
            'amount' => 2500,
            'reason' => 'Stale unpaid fine',
            'status' => FineStatus::Unpaid,
        ]);

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->getJson('/api/dashboard/license-issuance/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    private function asIssuer(): User
    {
        $issuer = User::factory()->dashboardEmployee('license_employee')->create();
        Sanctum::actingAs($issuer);

        return $issuer;
    }

    /**
     * @param  array<string, mixed>  $overrides
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
        unset($overrides['skip_payment'], $overrides['skip_documents'], $overrides['skip_tests']);

        $application = LicenseApplication::query()->create(array_merge([
            'application_number' => 'APP-ISS-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::Approved,
            'submitted_at' => now(),
            'approved_at' => now(),
            'issued_at' => null,
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
                'payment_number' => 'PAY-ISS-'.strtoupper(Str::random(8)),
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

                TestResult::query()->create([
                    'application_id' => $application->id,
                    'test_appointment_id' => $appointment->id,
                    'test_type_id' => $testType->id,
                    'result' => TestResultStatus::Passed,
                    'attempt_number' => 1,
                    'recorded_by' => User::factory()->dashboardEmployee('test_employee')->create()->id,
                    'recorded_at' => now()->subDays(2),
                ]);
            }
        }

        return $application->fresh(['serviceType', 'licenseType', 'citizen', 'relatedLicense']);
    }

    private function makeReadyFollowOnApplication(string $serviceCode): LicenseApplication
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'user_type' => UserType::Citizen,
            'email_verified_at' => now(),
        ]);
        $licenseTypeId = (int) LicenseType::query()->where('code', 'private')->value('id');
        $original = LicenseApplication::query()->create([
            'application_number' => 'APP-ORIG-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now()->subYears(9),
            'approved_at' => now()->subYears(9),
            'issued_at' => now()->subYears(9),
        ]);

        $license = License::query()->create([
            'license_number' => 'LIC-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'application_id' => $original->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(9)->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);

        return $this->makeIssuableApplication([
            'citizen' => $citizen,
            'skip_tests' => true,
            'service_type_id' => ServiceType::query()->where('code', $serviceCode)->value('id'),
            'related_license_id' => $license->id,
        ]);
    }
}
