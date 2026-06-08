<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Models\ApplicationDocument;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\RequiredDocument;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OtherLicenseServicesFlowTest extends TestCase
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
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function readyCitizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function employeeUser(): User
    {
        return User::factory()->dashboardEmployee('employee')->create();
    }

    /**
     * @return array{0: User, 1: License}
     */
    private function citizenWithRenewableLicense(): array
    {
        $citizen = $this->readyCitizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $originalApplication = LicenseApplication::query()->create([
            'application_number' => 'APP-ORIG-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now()->subYears(10),
            'approved_at' => now()->subYears(10),
            'issued_at' => now()->subYears(10),
        ]);

        $license = License::query()->create([
            'license_number' => 'LIC-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $originalApplication->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(9)->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);

        return [$citizen, $license];
    }

    private function createServiceApplication(User $citizen, License $license, string $serviceCode): LicenseApplication
    {
        Sanctum::actingAs($citizen);

        $response = $this->postJson('/api/applications', [
            'service_type_code' => $serviceCode,
            'related_license_id' => $license->id,
        ])->assertOk();

        return LicenseApplication::query()->findOrFail((int) $response->json('data.id'));
    }

    private function approveAllDocumentsForApplication(int $applicationId): void
    {
        $employee = $this->employeeUser();
        Sanctum::actingAs($employee);

        $pending = $this->getJson('/api/admin/documents/pending-review')->assertOk();
        $ids = collect($pending->json('data.items'))
            ->filter(function (array $item) use ($applicationId): bool {
                $itemApplicationId = (int) ($item['application']['id'] ?? $item['application_id'] ?? 0);

                return $itemApplicationId === $applicationId;
            })
            ->pluck('id')
            ->all();

        foreach ($ids as $documentId) {
            $this->postJson("/api/admin/documents/{$documentId}/approve")->assertOk();
        }
    }

    private function uploadAndSubmitDocuments(User $citizen, int $applicationId): void
    {
        Sanctum::actingAs($citizen);

        $checklist = $this->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->json('data');

        foreach ($checklist as $item) {
            $this->post(
                "/api/applications/{$applicationId}/documents",
                [
                    'required_document_id' => (int) $item['id'],
                    'file' => UploadedFile::fake()->create('doc-'.$item['code'].'.pdf', 80, 'application/pdf'),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }

        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();
    }

    private function completePayment(User $citizen, int $applicationId): void
    {
        Sanctum::actingAs($citizen);

        $paymentId = (int) $this->postJson("/api/applications/{$applicationId}/payments", [])->json('data.id');
        $this->postJson("/api/applications/{$applicationId}/payments/{$paymentId}/confirm", [])->assertOk();
    }

    public function test_citizen_can_create_renewal_application(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();

        Sanctum::actingAs($citizen);

        $response = $this->postJson('/api/applications', [
            'service_type_code' => 'renew_license',
            'related_license_id' => $license->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', ApplicationStatus::Draft->value)
            ->assertJsonPath('data.service_type.code', 'renew_license')
            ->assertJsonPath('data.related_license.id', $license->id)
            ->assertJsonPath('data.license_type.code', 'private');

        $this->assertDatabaseHas('license_applications', [
            'citizen_id' => $citizen->id,
            'related_license_id' => $license->id,
            'status' => ApplicationStatus::Draft->value,
        ]);
    }

    public function test_citizen_can_create_lost_replacement_application(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        Sanctum::actingAs($citizen);

        $response = $this->postJson('/api/applications', [
            'service_type_code' => 'lost_replacement',
            'related_license_id' => $license->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.service_type.code', 'lost_replacement')
            ->assertJsonPath('data.related_license.id', $license->id);
    }

    public function test_citizen_can_create_damaged_replacement_application(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'service_type_code' => 'damaged_replacement',
            'related_license_id' => $license->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.service_type.code', 'damaged_replacement')
            ->assertJsonPath('data.related_license.id', $license->id);
    }

    public function test_renew_requires_related_license_id(): void
    {
        $citizen = $this->readyCitizen();
        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'service_type_code' => 'renew_license',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['related_license_id']);
    }

    public function test_cannot_use_another_citizens_license(): void
    {
        [, $license] = $this->citizenWithRenewableLicense();
        $other = $this->readyCitizen();

        Sanctum::actingAs($other);

        $this->postJson('/api/applications', [
            'service_type_code' => 'renew_license',
            'related_license_id' => $license->id,
        ])->assertStatus(403);
    }

    public function test_duplicate_renew_application_is_blocked(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        $this->createServiceApplication($citizen, $license, 'renew_license');

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'service_type_code' => 'renew_license',
            'related_license_id' => $license->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.applications.duplicate_active_application_license'));
    }

    public function test_required_documents_differ_by_service_type(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();

        $renew = $this->createServiceApplication($citizen, $license, 'renew_license');
        Sanctum::actingAs($citizen);

        $renewCodes = collect($this->getJson("/api/applications/{$renew->id}/required-documents")->json('data'))
            ->pluck('code')
            ->all();

        $this->assertContains('recent_personal_photo', $renewCodes);
        $this->assertNotContains('loss_declaration', $renewCodes);

        $lost = $this->createServiceApplication($citizen, $license, 'lost_replacement');
        $lostCodes = collect($this->getJson("/api/applications/{$lost->id}/required-documents")->json('data'))
            ->pluck('code')
            ->all();

        $this->assertContains('loss_declaration', $lostCodes);

        $damaged = $this->createServiceApplication($citizen, $license, 'damaged_replacement');
        $damagedCodes = collect($this->getJson("/api/applications/{$damaged->id}/required-documents")->json('data'))
            ->pluck('code')
            ->all();

        $this->assertContains('damaged_license_proof', $damagedCodes);
    }

    public function test_document_review_moves_renew_application_to_payment_pending(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        $application = $this->createServiceApplication($citizen, $license, 'renew_license');

        $this->uploadAndSubmitDocuments($citizen, $application->id);
        $this->approveAllDocumentsForApplication($application->id);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::PaymentPending->value,
        ]);
    }

    public function test_renew_payment_completion_moves_to_approved_not_appointment_pending(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        $application = $this->createServiceApplication($citizen, $license, 'renew_license');

        $this->uploadAndSubmitDocuments($citizen, $application->id);
        $this->approveAllDocumentsForApplication($application->id);
        $this->completePayment($citizen, $application->id);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::Approved->value,
        ]);
    }

    public function test_appointment_actions_blocked_for_renew_application(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        $application = $this->createServiceApplication($citizen, $license, 'renew_license');

        Sanctum::actingAs($citizen);

        $this->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk()
            ->assertJsonPath('data.blocked', true);

        $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => 1,
        ])->assertStatus(422);
    }

    public function test_employee_can_issue_renewed_license(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        $application = $this->createServiceApplication($citizen, $license, 'renew_license');

        $this->uploadAndSubmitDocuments($citizen, $application->id);
        $this->approveAllDocumentsForApplication($application->id);
        $this->completePayment($citizen, $application->id);

        Sanctum::actingAs($this->employeeUser());

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertOk()
            ->assertJsonPath('data.status', LicenseStatus::Active->value);

        $license->refresh();
        $this->assertEquals(LicenseStatus::Renewed, $license->status);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::LicenseIssued->value,
        ]);
    }

    public function test_employee_can_issue_lost_replacement_license(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        $application = $this->createServiceApplication($citizen, $license, 'lost_replacement');

        $this->uploadAndSubmitDocuments($citizen, $application->id);
        $this->approveAllDocumentsForApplication($application->id);
        $this->completePayment($citizen, $application->id);

        Sanctum::actingAs($this->employeeUser());

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")->assertOk();

        $license->refresh();
        $this->assertEquals(LicenseStatus::Inactive, $license->status);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::LicenseIssued->value,
        ]);
    }

    public function test_ai_agent_renew_flow_proposes_create_application(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        Sanctum::actingAs($citizen);

        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي جدد رخصتي',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'create_renew_license_application')
            ->assertJsonPath('data.pending_action.name', 'create_application')
            ->assertJsonPath('data.pending_action.arguments.service_type_code', 'renew_license')
            ->assertJsonPath('data.pending_action.arguments.related_license_id', $license->id)
            ->assertJsonPath('data.requires_confirmation', true);

        $this->assertStringNotContainsString('messages.', (string) $response->json('data.reply'));
    }

    public function test_ai_agent_lost_replacement_flow(): void
    {
        [$citizen, $license] = $this->citizenWithRenewableLicense();
        Sanctum::actingAs($citizen);

        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'ضاعت رخصتي',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'create_lost_replacement_application')
            ->assertJsonPath('data.pending_action.arguments.service_type_code', 'lost_replacement')
            ->assertJsonPath('data.pending_action.arguments.related_license_id', $license->id);
    }

    public function test_ai_agent_no_licenses_message(): void
    {
        $citizen = $this->readyCitizen();
        Sanctum::actingAs($citizen);

        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي جدد رخصتي',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.reply', 'لا يوجد لديك رخصة يمكن تنفيذ هذه الخدمة عليها حالياً.');
    }
}
