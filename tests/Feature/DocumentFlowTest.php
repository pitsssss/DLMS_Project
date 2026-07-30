<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Models\ApplicationDocument;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDocumentFile;
use Tests\TestCase;

class DocumentFlowTest extends TestCase
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
            RequiredDocumentsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
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

    private function createNewLicenseApplication(User $citizen): int
    {
        Sanctum::actingAs($citizen);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $response = $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ]);

        $response->assertOk();

        return (int) $response->json('data.id');
    }

    private function uploadAllRequired(User $citizen, int $applicationId): void
    {
        Sanctum::actingAs($citizen);

        $checklist = $this->getRequiredChecklist($applicationId);

        $this->assertNotEmpty($checklist);

        foreach ($checklist as $item) {
            $this->uploadRequiredDocument($applicationId, (int) $item['id'], (string) $item['code']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getRequiredChecklist(int $applicationId): array
    {
        return $this->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');
    }

    private function uploadRequiredDocument(int $applicationId, int $requiredDocumentId, string $code): void
    {
        $this->post(
            "/api/applications/{$applicationId}/documents",
            [
                'required_document_id' => $requiredDocumentId,
                'file' => FakeDocumentFile::pdf('doc-'.$code.'.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertOk();
    }

    /**
     * @param  list<array<string, mixed>>  $checklist
     */
    private function assertChecklistUploadCounts(array $checklist, int $uploadedCount, int $totalCount): void
    {
        $this->assertCount($totalCount, $checklist);

        $uploadedItems = collect($checklist)->filter(
            fn (array $item): bool => ! empty($item['latest_document'])
        );

        $this->assertCount($uploadedCount, $uploadedItems);
        $this->assertCount($totalCount - $uploadedCount, collect($checklist)->filter(
            fn (array $item): bool => empty($item['latest_document'])
        ));
    }

    public function test_required_checklist_before_upload(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);

        Sanctum::actingAs($citizen);
        $checklist = $this->getRequiredChecklist($applicationId);

        $this->assertNotEmpty($checklist);
        $this->assertChecklistUploadCounts($checklist, 0, count($checklist));

        foreach ($checklist as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('is_required', $item);
            $this->assertTrue((bool) $item['is_required']);
            $this->assertNull($item['latest_document']);
        }
    }

    public function test_required_checklist_after_one_upload(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);

        Sanctum::actingAs($citizen);
        $before = $this->getRequiredChecklist($applicationId);
        $firstRequiredId = (int) $before[0]['id'];
        $firstCode = (string) $before[0]['code'];

        $this->uploadRequiredDocument($applicationId, $firstRequiredId, $firstCode);

        $after = $this->getRequiredChecklist($applicationId);
        $this->assertChecklistUploadCounts($after, 1, count($before));

        $uploadedItem = collect($after)->firstWhere('id', $firstRequiredId);
        $this->assertNotNull($uploadedItem);
        $this->assertNotNull($uploadedItem['latest_document']);
        $this->assertEquals(DocumentStatus::PendingReview->value, $uploadedItem['latest_document']['status']);
        $this->assertEquals('doc-'.$firstCode.'.pdf', $uploadedItem['latest_document']['original_name']);
    }

    public function test_required_checklist_after_multiple_uploads(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);

        Sanctum::actingAs($citizen);
        $checklist = $this->getRequiredChecklist($applicationId);

        $this->uploadRequiredDocument($applicationId, (int) $checklist[0]['id'], (string) $checklist[0]['code']);
        $this->uploadRequiredDocument($applicationId, (int) $checklist[1]['id'], (string) $checklist[1]['code']);

        $after = $this->getRequiredChecklist($applicationId);
        $this->assertChecklistUploadCounts($after, 2, count($checklist));
    }

    public function test_required_checklist_after_all_uploads(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);

        Sanctum::actingAs($citizen);
        $before = $this->getRequiredChecklist($applicationId);
        $this->uploadAllRequired($citizen, $applicationId);

        $after = $this->getRequiredChecklist($applicationId);
        $this->assertChecklistUploadCounts($after, count($before), count($before));

        foreach ($after as $item) {
            $this->assertNotNull($item['latest_document']);
            $this->assertEquals(DocumentStatus::PendingReview->value, $item['latest_document']['status']);
        }
    }

    public function test_citizen_cannot_view_another_citizens_required_checklist(): void
    {
        $owner = $this->readyCitizen();
        $other = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($owner);

        Sanctum::actingAs($other);
        $this->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_required_checklist_shows_rejected_document_status(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);
        $this->uploadAllRequired($citizen, $applicationId);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        $employee = $this->employeeUser();
        Sanctum::actingAs($employee);

        $firstDocId = (int) $this->getJson('/api/admin/documents/pending-review')->json('data.items.0.id');
        $requiredDocumentId = (int) ApplicationDocument::query()->findOrFail($firstDocId)->required_document_id;

        $this->postJson("/api/admin/documents/{$firstDocId}/reject", [
            'rejection_reason' => 'Illegible scan.',
        ])->assertOk();

        Sanctum::actingAs($citizen);
        $checklist = $this->getRequiredChecklist($applicationId);
        $rejectedItem = collect($checklist)->firstWhere('id', $requiredDocumentId);

        $this->assertNotNull($rejectedItem);
        $this->assertNotNull($rejectedItem['latest_document']);
        $this->assertEquals(DocumentStatus::Rejected->value, $rejectedItem['latest_document']['status']);
        $this->assertEquals('Illegible scan.', $rejectedItem['latest_document']['rejection_reason']);
    }

    public function test_required_checklist_shows_approved_document_status(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);
        $this->uploadAllRequired($citizen, $applicationId);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        $employee = $this->employeeUser();
        Sanctum::actingAs($employee);

        $firstDocId = (int) $this->getJson('/api/admin/documents/pending-review')->json('data.items.0.id');
        $requiredDocumentId = (int) ApplicationDocument::query()->findOrFail($firstDocId)->required_document_id;

        $this->postJson("/api/admin/documents/{$firstDocId}/approve")->assertOk();

        Sanctum::actingAs($citizen);
        $checklist = $this->getRequiredChecklist($applicationId);
        $approvedItem = collect($checklist)->firstWhere('id', $requiredDocumentId);

        $this->assertNotNull($approvedItem);
        $this->assertNotNull($approvedItem['latest_document']);
        $this->assertEquals(DocumentStatus::Approved->value, $approvedItem['latest_document']['status']);
    }

    public function test_required_checklist_does_not_fail_after_upload(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);

        Sanctum::actingAs($citizen);
        $checklist = $this->getRequiredChecklist($applicationId);
        $this->uploadRequiredDocument($applicationId, (int) $checklist[0]['id'], (string) $checklist[0]['code']);

        $response = $this->getJson("/api/applications/{$applicationId}/required-documents");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissing(['exception'])
            ->assertJsonMissing(['trace']);

        $this->assertNotNull($response->json('data.0.latest_document.id'));
    }

    public function test_full_document_review_moves_application_to_payment_pending(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);
        $this->uploadAllRequired($citizen, $applicationId);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")
            ->assertOk()
            ->assertJsonPath('data.status', ApplicationStatus::DocumentsUnderReview->value);

        $employee = $this->employeeUser();
        Sanctum::actingAs($employee);

        $pending = $this->getJson('/api/admin/documents/pending-review')->assertOk();
        $ids = collect($pending->json('data.items'))->pluck('id')->all();
        $this->assertCount(4, $ids);

        foreach ($ids as $i => $documentId) {
            $response = $this->postJson("/api/admin/documents/{$documentId}/approve");
            $response->assertOk();

            if ($i < 3) {
                $this->assertDatabaseHas('license_applications', [
                    'id' => $applicationId,
                    'status' => ApplicationStatus::DocumentsUnderReview->value,
                ]);
            }
        }

        $this->assertDatabaseHas('license_applications', [
            'id' => $applicationId,
            'status' => ApplicationStatus::PaymentPending->value,
        ]);
    }

    public function test_reject_document_sets_application_to_documents_rejected_and_allows_resubmit(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);
        $this->uploadAllRequired($citizen, $applicationId);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        $employee = $this->employeeUser();
        Sanctum::actingAs($employee);

        $firstDocId = $this->getJson('/api/admin/documents/pending-review')->json('data.items.0.id');

        $this->postJson("/api/admin/documents/{$firstDocId}/reject", [
            'rejection_reason' => 'Illegible scan.',
        ])->assertOk();

        $requiredDocumentId = (int) ApplicationDocument::query()->findOrFail($firstDocId)->required_document_id;

        $this->assertDatabaseHas('license_applications', [
            'id' => $applicationId,
            'status' => ApplicationStatus::DocumentsRejected->value,
        ]);

        Sanctum::actingAs($citizen);
        $this->post(
            "/api/applications/{$applicationId}/documents",
            [
                'required_document_id' => $requiredDocumentId,
                'file' => FakeDocumentFile::pdf('replacement.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->postJson("/api/applications/{$applicationId}/submit-documents")
            ->assertOk()
            ->assertJsonPath('data.status', ApplicationStatus::DocumentsUnderReview->value);
    }

    public function test_citizen_cannot_upload_while_documents_under_review(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);
        $this->uploadAllRequired($citizen, $applicationId);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        $requiredId = $this->getJson("/api/applications/{$applicationId}/required-documents")->json('data.0.id');

        $this->post(
            "/api/applications/{$applicationId}/documents",
            [
                'required_document_id' => $requiredId,
                'file' => FakeDocumentFile::pdf('x.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertStatus(422);
    }

    public function test_citizen_cannot_list_pending_review_documents(): void
    {
        $citizen = $this->readyCitizen();
        Sanctum::actingAs($citizen);
        $this->getJson('/api/admin/documents/pending-review')->assertForbidden();
    }

    public function test_submit_requires_all_required_documents(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")
            ->assertStatus(422);
    }

    public function test_application_show_includes_documents_array(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createNewLicenseApplication($citizen);
        $this->uploadAllRequired($citizen, $applicationId);

        Sanctum::actingAs($citizen);
        $this->getJson("/api/applications/{$applicationId}")
            ->assertOk()
            ->assertJsonPath('data.documents.0.status', DocumentStatus::PendingReview->value);
    }
}
