<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Models\ApplicationDocument;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\AIAgent\Support\AgentWorkflowPhraseMatcher;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Services\AgentActionExecutor;
use App\Modules\Dashboard\Services\DashboardDocumentReviewService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;
use App\Modules\AIAgent\Enums\AgentSessionStatus;

class AIAgentDocumentUploadTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

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

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function createDraftApplication(User $citizen, string $applicationNumber = 'APP-AI-UPLOAD'): LicenseApplication
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return LicenseApplication::query()->create([
            'application_number' => $applicationNumber,
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft->value,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requiredChecklist(int $applicationId): array
    {
        $resp = $this->getJson("/api/applications/{$applicationId}/required-documents");
        $resp->assertOk();

        return $resp->json('data');
    }

    private function uploadAllRequiredDocuments(User $citizen, int $applicationId): void
    {
        Sanctum::actingAs($citizen);

        $checklist = $this->requiredChecklist($applicationId);
        $this->assertNotEmpty($checklist);

        foreach ($checklist as $required) {
            $this->post(
                "/api/applications/{$applicationId}/documents",
                [
                    'required_document_id' => (int) $required['id'],
                    'file' => UploadedFile::fake()->create('doc-'.$required['code'].'.pdf', 80, 'application/pdf'),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }
    }

    private function createAgentSession(User $citizen): AIAgentSession
    {
        return AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => AgentSessionStatus::Active,
            'context' => [],
            'last_message_at' => now(),
        ]);
    }

    private function mockGemini(?array $response = null): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')
            ->andReturn($response);

        $this->instance(GeminiAgentClient::class, $mock);
    }

    public function test_upload_agent_document_creates_document_record_and_returns_checklist(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-UP-1');
        $session = $this->createAgentSession($citizen);

        $checklist = $this->requiredChecklist($application->id);
        $required = (array) $checklist[0];

        $file = UploadedFile::fake()->create('upload-'.$required['code'].'.pdf', 80, 'application/pdf');

        $response = $this->post(
            "/api/ai-agent/sessions/{$session->id}/documents",
            [
                'application_id' => $application->id,
                'required_document_id' => (int) $required['id'],
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', $session->id)
            ->assertJsonPath('data.application.id', $application->id)
            ->assertJsonPath('data.checklist.all_required_uploaded', false)
            ->assertJsonPath('data.checklist.can_submit_for_review', false);

        $this->assertIsNumeric($response->json('data.document.id'));
        $this->assertIsArray($response->json('data.checklist.missing'));

        $application->refresh();
        $this->assertSame(ApplicationStatus::Draft, $application->status);

        $doc = ApplicationDocument::query()
            ->where('application_id', $application->id)
            ->where('required_document_id', (int) $required['id'])
            ->first();

        $this->assertNotNull($doc);
        $this->assertSame(DocumentStatus::PendingReview, $doc->status);

        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));

        $session->refresh();
        $this->assertSame($application->id, $session->context['last_application_id'] ?? null);
    }

    public function test_upload_agent_document_rejects_when_session_missing(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-UP-2');
        $checklist = $this->requiredChecklist($application->id);
        $required = (array) $checklist[0];

        $file = UploadedFile::fake()->create('upload-'.$required['code'].'.pdf', 80, 'application/pdf');

        $this->post(
            '/api/ai-agent/sessions/999999/documents',
            [
                'application_id' => $application->id,
                'required_document_id' => (int) $required['id'],
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        )->assertStatus(404);
    }

    public function test_upload_agent_document_rejects_foreign_application_id(): void
    {
        Storage::fake('local');

        $owner = $this->citizen();
        $other = $this->citizen();

        $application = $this->createDraftApplication($owner, 'APP-AI-UP-3');
        $session = $this->createAgentSession($other);

        // Pick required_document_id based on owner's checklist (do not query checklist while acting as foreign user).
        Sanctum::actingAs($owner);
        $checklist = $this->requiredChecklist($application->id);
        $required = (array) $checklist[0];

        $file = UploadedFile::fake()->create('upload-'.$required['code'].'.pdf', 80, 'application/pdf');

        Sanctum::actingAs($other);
        $this->post(
            "/api/ai-agent/sessions/{$session->id}/documents",
            [
                'application_id' => $application->id,
                'required_document_id' => (int) $required['id'],
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        )->assertStatus(404);
    }

    public function test_upload_agent_document_does_not_auto_transition_to_documents_under_review(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-UP-4');
        $session = $this->createAgentSession($citizen);

        $checklist = $this->requiredChecklist($application->id);
        $required = (array) $checklist[0];

        // Upload a single document only.
        $file = UploadedFile::fake()->create('upload-'.$required['code'].'.pdf', 80, 'application/pdf');

        $this->post(
            "/api/ai-agent/sessions/{$session->id}/documents",
            [
                'application_id' => $application->id,
                'required_document_id' => (int) $required['id'],
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::Draft, $application->status);
        $this->assertSame(0, LicenseApplication::query()
            ->whereKey($application->id)
            ->where('status', ApplicationStatus::DocumentsUnderReview->value)
            ->count());
    }

    public function test_upload_all_required_docs_via_agent_then_submit_requires_confirmation_and_transitions(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-UP-5');
        $session = $this->createAgentSession($citizen);

        $checklist = $this->requiredChecklist($application->id);
        $requiredDocs = array_values(array_filter($checklist, static fn (array $item): bool => (bool) ($item['is_required'] ?? false) === true));

        $this->assertNotEmpty($requiredDocs);

        foreach ($requiredDocs as $required) {
            $file = UploadedFile::fake()->create('upload-'.$required['code'].'.pdf', 80, 'application/pdf');

            $this->post(
                "/api/ai-agent/sessions/{$session->id}/documents",
                [
                    'application_id' => $application->id,
                    'required_document_id' => (int) $required['id'],
                    'file' => $file,
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }

        $application->refresh();
        $this->assertSame(ApplicationStatus::Draft, $application->status);

        $this->mockGemini(null);
        $messageResponse = $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
            'session_id' => $session->id,
        ])->assertOk();

        $pendingActionId = (int) $messageResponse->json('data.pending_action.id');
        $this->assertSame('awaiting_confirmation', $messageResponse->json('data.pending_action.status'));

        $this->postJson("/api/ai-agent/actions/{$pendingActionId}/confirm")->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsUnderReview, $application->status);

        $dashboard = app(DashboardDocumentReviewService::class);
        $stats = $dashboard->stats();
        $this->assertGreaterThanOrEqual(1, (int) ($stats['awaiting_review'] ?? 0));
    }

    public function test_upload_rejected_document_replacement_keeps_documents_rejected(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-UP-6');
        $applicationId = $application->id;

        // Upload all required documents via REST, then submit, then reject one document to reach DocumentsRejected.
        $this->uploadAllRequiredDocuments($citizen, $applicationId);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        $employee = User::factory()->dashboardEmployee('employee')->create();
        Sanctum::actingAs($employee);

        $pending = $this->getJson('/api/admin/documents/pending-review')->assertOk();
        $firstDocId = (int) $pending->json('data.items.0.id');
        $rejectedDoc = ApplicationDocument::query()->findOrFail($firstDocId);

        $this->postJson("/api/admin/documents/{$firstDocId}/reject", [
            'rejection_reason' => 'Illegible scan.',
        ])->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsRejected, $application->status);

        // Replace the rejected required document via AI Agent endpoint.
        $session = $this->createAgentSession($citizen);
        Sanctum::actingAs($citizen);

        $replacementFile = UploadedFile::fake()->create('replacement-'.$rejectedDoc->requiredDocument?->code.'.pdf', 90, 'application/pdf');

        $this->post(
            "/api/ai-agent/sessions/{$session->id}/documents",
            [
                'application_id' => $applicationId,
                'required_document_id' => (int) $rejectedDoc->required_document_id,
                'file' => $replacementFile,
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsRejected, $application->status);
        $this->assertSame(
            DocumentStatus::PendingReview,
            ApplicationDocument::query()
                ->where('application_id', $applicationId)
                ->where('required_document_id', $rejectedDoc->required_document_id)
                ->latest('id')
                ->firstOrFail()
                ->status
        );
    }
}

