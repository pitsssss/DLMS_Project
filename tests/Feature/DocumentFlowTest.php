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
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
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
        return User::factory()->create([
            'profile_completed' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function employeeUser(): User
    {
        $role = Role::query()->where('name', 'employee')->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
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

        $checklist = $this->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($checklist);

        foreach ($checklist as $item) {
            $this->post(
                "/api/applications/{$applicationId}/documents",
                [
                    'required_document_id' => $item['id'],
                    'file' => UploadedFile::fake()->create('doc-'.$item['code'].'.pdf', 80, 'application/pdf'),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }
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
                'file' => UploadedFile::fake()->create('replacement.pdf', 90, 'application/pdf'),
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
                'file' => UploadedFile::fake()->create('x.pdf', 50, 'application/pdf'),
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
