<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Models\ApplicationDocument;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\RequiredDocument;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentSessionStatus;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use App\Modules\Dashboard\Services\DashboardDocumentReviewService;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\FakeDocumentFile;
use Tests\TestCase;

class AIAgentDocumentUploadTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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
                    'file' => FakeDocumentFile::pdf('doc-'.$required['code'].'.pdf'),
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

    private function agentUpload(int $sessionId, int $applicationId, int $requiredDocumentId, $file)
    {
        return $this->post(
            "/api/ai-agent/sessions/{$sessionId}/documents",
            [
                'application_id' => $applicationId,
                'required_document_id' => $requiredDocumentId,
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );
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

        $response = $this->agentUpload(
            $session->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::pdf('upload-'.$required['code'].'.pdf')
        )->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', $session->id)
            ->assertJsonPath('data.application.id', $application->id)
            ->assertJsonPath('data.checklist.all_required_uploaded', false)
            ->assertJsonPath('data.checklist.can_submit_for_review', false);

        $documentId = (int) $response->json('data.document.id');
        $this->assertGreaterThan(0, $documentId);
        $this->assertIsArray($response->json('data.checklist.missing'));

        $application->refresh();
        $this->assertSame(ApplicationStatus::Draft, $application->status);

        $doc = ApplicationDocument::query()->find($documentId);
        $this->assertNotNull($doc);
        $this->assertSame(DocumentStatus::PendingReview, $doc->status);
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));

        $session->refresh();
        $this->assertSame($application->id, $session->context['last_application_id'] ?? null);
        $this->assertSame($documentId, $session->context['last_uploaded_document_id'] ?? null);
        $this->assertSame((int) $required['id'], $session->context['last_required_document_id'] ?? null);
        $this->assertArrayNotHasKey('file_path', $session->context);
        $this->assertArrayNotHasKey('original_name', $session->context);
        $this->assertArrayNotHasKey('base64', $session->context);
        $this->assertArrayNotHasKey('mime_type', $session->context);
    }

    public function test_upload_agent_document_rejects_when_session_missing(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-UP-2');
        $checklist = $this->requiredChecklist($application->id);
        $required = (array) $checklist[0];

        $this->agentUpload(
            999999,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::pdf('upload-'.$required['code'].'.pdf')
        )->assertStatus(404);
    }

    public function test_upload_agent_document_rejects_foreign_session(): void
    {
        Storage::fake('local');

        $owner = $this->citizen();
        $other = $this->citizen();
        $application = $this->createDraftApplication($owner, 'APP-AI-UP-SESSION');
        $foreignSession = $this->createAgentSession($owner);

        Sanctum::actingAs($owner);
        $checklist = $this->requiredChecklist($application->id);
        $required = (array) $checklist[0];

        Sanctum::actingAs($other);
        $this->agentUpload(
            $foreignSession->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::pdf('upload-'.$required['code'].'.pdf')
        )->assertStatus(404);
    }

    public function test_upload_agent_document_rejects_foreign_application_id(): void
    {
        Storage::fake('local');

        $owner = $this->citizen();
        $other = $this->citizen();

        $application = $this->createDraftApplication($owner, 'APP-AI-UP-3');
        $session = $this->createAgentSession($other);

        Sanctum::actingAs($owner);
        $checklist = $this->requiredChecklist($application->id);
        $required = (array) $checklist[0];

        Sanctum::actingAs($other);
        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::pdf('upload-'.$required['code'].'.pdf')
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

        $notificationsBefore = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'application.documents_under_review')
            ->count();

        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::pdf('upload-'.$required['code'].'.pdf')
        )->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::Draft, $application->status);

        $this->assertSame(
            $notificationsBefore,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', 'application.documents_under_review')
                ->count()
        );

        $stats = app(DashboardDocumentReviewService::class)->stats();
        $this->assertSame(0, (int) ($stats['awaiting_review'] ?? 0));
    }

    public function test_upload_all_required_docs_via_agent_then_submit_requires_confirmation_and_transitions(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-UP-5');
        $session = $this->createAgentSession($citizen);

        $checklist = $this->requiredChecklist($application->id);
        $requiredDocs = array_values(array_filter(
            $checklist,
            static fn (array $item): bool => (bool) ($item['is_required'] ?? false) === true
        ));

        $this->assertNotEmpty($requiredDocs);

        foreach ($requiredDocs as $required) {
            $this->agentUpload(
                $session->id,
                $application->id,
                (int) $required['id'],
                FakeDocumentFile::pdf('upload-'.$required['code'].'.pdf')
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

        $notificationsBefore = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'application.documents_under_review')
            ->count();

        $this->postJson("/api/ai-agent/actions/{$pendingActionId}/confirm")->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsUnderReview, $application->status);

        $this->assertSame(
            $notificationsBefore + 1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', 'application.documents_under_review')
                ->count()
        );

        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        Sanctum::actingAs($reviewer);

        $queue = $this->getJson('/api/dashboard/document-reviews')->assertOk();
        $applicationIds = collect($queue->json('data.items'))->pluck('id')->all();
        $this->assertContains($application->id, $applicationIds);
    }

    public function test_upload_rejected_document_replacement_keeps_documents_rejected(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-UP-6');
        $applicationId = $application->id;

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

        $session = $this->createAgentSession($citizen);
        Sanctum::actingAs($citizen);

        $this->agentUpload(
            $session->id,
            $applicationId,
            (int) $rejectedDoc->required_document_id,
            FakeDocumentFile::pdf('replacement-'.$rejectedDoc->requiredDocument?->code.'.pdf')
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

    public function test_text_file_disguised_as_pdf_is_rejected(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $application = $this->createDraftApplication($citizen, 'APP-AI-MIME-1');
        $session = $this->createAgentSession($citizen);
        $required = (array) $this->requiredChecklist($application->id)[0];

        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::textDisguisedAsPdf('document.pdf')
        )->assertStatus(422);

        $this->assertSame(0, ApplicationDocument::query()->where('application_id', $application->id)->count());
    }

    public function test_text_file_disguised_as_jpeg_is_rejected(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $application = $this->createDraftApplication($citizen, 'APP-AI-MIME-2');
        $session = $this->createAgentSession($citizen);
        $required = (array) $this->requiredChecklist($application->id)[0];

        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::textDisguisedAsJpeg('document.jpg')
        )->assertStatus(422);
    }

    public function test_real_pdf_jpeg_and_png_are_accepted(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $application = $this->createDraftApplication($citizen, 'APP-AI-MIME-OK');
        $session = $this->createAgentSession($citizen);
        $checklist = $this->requiredChecklist($application->id);

        $this->assertGreaterThanOrEqual(3, count($checklist));

        $this->agentUpload($session->id, $application->id, (int) $checklist[0]['id'], FakeDocumentFile::pdf('ok.PDF'))
            ->assertOk()
            ->assertJsonPath('data.document.status', DocumentStatus::PendingReview->value);

        $this->agentUpload($session->id, $application->id, (int) $checklist[1]['id'], FakeDocumentFile::jpeg('photo.JPG'))
            ->assertOk();

        $this->agentUpload($session->id, $application->id, (int) $checklist[2]['id'], FakeDocumentFile::png('scan.PNG'))
            ->assertOk();
    }

    public function test_double_extension_with_php_content_is_rejected(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $application = $this->createDraftApplication($citizen, 'APP-AI-MIME-DBL');
        $session = $this->createAgentSession($citizen);
        $required = (array) $this->requiredChecklist($application->id)[0];

        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::phpDisguisedAsPdf('file.php.pdf')
        )->assertStatus(422);
    }

    public function test_upload_blocked_for_documents_under_review_payment_pending_and_license_issued(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $session = $this->createAgentSession($citizen);

        foreach ([
            [ApplicationStatus::DocumentsUnderReview, 'APP-AI-BLOCK-DUR'],
            [ApplicationStatus::PaymentPending, 'APP-AI-BLOCK-PAY'],
            [ApplicationStatus::LicenseIssued, 'APP-AI-BLOCK-LIC'],
        ] as [$status, $number]) {
            $application = $this->createDraftApplication($citizen, $number);
            $application->status = $status;
            $application->save();

            $required = (array) $this->requiredChecklist($application->id)[0];

            $this->agentUpload(
                $session->id,
                $application->id,
                (int) $required['id'],
                FakeDocumentFile::pdf('blocked.pdf')
            )->assertStatus(422);

            $this->assertSame(0, ApplicationDocument::query()->where('application_id', $application->id)->count());
        }
    }

    public function test_approved_document_cannot_be_replaced_via_agent_or_rest(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-APPROVED');
        $this->uploadAllRequiredDocuments($citizen, $application->id);
        $this->postJson("/api/applications/{$application->id}/submit-documents")->assertOk();

        $employee = User::factory()->dashboardEmployee('employee')->create();
        Sanctum::actingAs($employee);

        $pending = $this->getJson('/api/admin/documents/pending-review')->assertOk();
        $firstDocId = (int) $pending->json('data.items.0.id');
        $approved = ApplicationDocument::query()->findOrFail($firstDocId);
        $oldPath = $approved->file_path;

        $this->postJson("/api/admin/documents/{$firstDocId}/approve")->assertOk();
        $approved->refresh();
        $this->assertSame(DocumentStatus::Approved, $approved->status);

        // Application may still be DocumentsUnderReview until all are approved — force Draft-like
        // editable status only if needed. Approve leaves app in DocumentsUnderReview typically,
        // which already blocks upload. Force DocumentsRejected with this doc still Approved to
        // isolate the Approved guard from the application-status guard.
        $application->refresh();
        $application->status = ApplicationStatus::DocumentsRejected;
        $application->save();

        $session = $this->createAgentSession($citizen);
        Sanctum::actingAs($citizen);

        $countBefore = ApplicationDocument::query()
            ->where('application_id', $application->id)
            ->where('required_document_id', $approved->required_document_id)
            ->count();

        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $approved->required_document_id,
            FakeDocumentFile::pdf('replace-approved.pdf')
        )->assertStatus(422);

        $this->post(
            "/api/applications/{$application->id}/documents",
            [
                'required_document_id' => (int) $approved->required_document_id,
                'file' => FakeDocumentFile::pdf('replace-approved-rest.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertStatus(422);

        $approved->refresh();
        $this->assertSame(DocumentStatus::Approved, $approved->status);
        $this->assertSame($oldPath, $approved->file_path);
        $this->assertTrue(Storage::disk('local')->exists($oldPath));

        $this->assertSame(
            $countBefore,
            ApplicationDocument::query()
                ->where('application_id', $application->id)
                ->where('required_document_id', $approved->required_document_id)
                ->count()
        );
    }

    public function test_empty_and_oversized_files_are_rejected(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $application = $this->createDraftApplication($citizen, 'APP-AI-SIZE');
        $session = $this->createAgentSession($citizen);
        $required = (array) $this->requiredChecklist($application->id)[0];

        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::emptyPdf()
        )->assertStatus(422);

        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $required['id'],
            FakeDocumentFile::oversizedPdf('huge.pdf', 6000)
        )->assertStatus(422);
    }

    public function test_missing_and_inapplicable_required_document_ids_are_rejected(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $application = $this->createDraftApplication($citizen, 'APP-AI-REQ');
        $session = $this->createAgentSession($citizen);

        $this->agentUpload(
            $session->id,
            $application->id,
            999999,
            FakeDocumentFile::pdf('missing-req.pdf')
        )->assertStatus(422);

        $truckLicense = LicenseType::query()->where('code', 'truck')->first();
        $inapplicable = null;

        if ($truckLicense !== null) {
            $inapplicable = RequiredDocument::query()
                ->where('is_active', true)
                ->where('license_type_id', $truckLicense->id)
                ->where('license_type_id', '!=', $application->license_type_id)
                ->first();
        }

        if ($inapplicable === null) {
            $inapplicable = RequiredDocument::query()->create([
                'name' => 'وثيقة غير منطبقة للاختبار',
                'code' => 'inapplicable_test_doc_'.uniqid(),
                'is_required' => true,
                'is_active' => true,
                'license_type_id' => LicenseType::query()->where('code', '!=', 'private')->value('id'),
                'service_type_id' => null,
                'allowed_extensions' => ['pdf'],
                'max_size_kb' => 4096,
            ]);
        }

        $this->agentUpload(
            $session->id,
            $application->id,
            (int) $inapplicable->id,
            FakeDocumentFile::pdf('inapplicable.pdf')
        )->assertStatus(422);
    }

    public function test_employee_without_permission_and_citizen_cannot_access_dashboard_review(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-AI-PERM');
        $session = $this->createAgentSession($citizen);
        $checklist = $this->requiredChecklist($application->id);
        $requiredDocs = array_values(array_filter(
            $checklist,
            static fn (array $item): bool => (bool) ($item['is_required'] ?? false)
        ));

        foreach ($requiredDocs as $required) {
            $this->agentUpload(
                $session->id,
                $application->id,
                (int) $required['id'],
                FakeDocumentFile::pdf('upload-'.$required['code'].'.pdf')
            )->assertOk();
        }

        $this->mockGemini(null);
        $pendingActionId = (int) $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
            'session_id' => $session->id,
        ])->assertOk()->json('data.pending_action.id');

        $this->postJson("/api/ai-agent/actions/{$pendingActionId}/confirm")->assertOk();

        Sanctum::actingAs($citizen);
        $this->getJson('/api/dashboard/document-reviews')->assertForbidden();

        $unauthorized = User::factory()->dashboardEmployee('fines_employee')->create();
        Sanctum::actingAs($unauthorized);
        $this->getJson('/api/dashboard/document-reviews')->assertForbidden();

        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        Sanctum::actingAs($reviewer);
        $queue = $this->getJson('/api/dashboard/document-reviews')->assertOk();
        $this->assertContains($application->id, collect($queue->json('data.items'))->pluck('id')->all());
    }

    public function test_rest_valid_upload_still_works_with_mime_validation(): void
    {
        Storage::fake('local');
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $application = $this->createDraftApplication($citizen, 'APP-REST-MIME');
        $required = (array) $this->requiredChecklist($application->id)[0];

        $this->post(
            "/api/applications/{$application->id}/documents",
            [
                'required_document_id' => (int) $required['id'],
                'file' => FakeDocumentFile::pdf('rest-valid.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->post(
            "/api/applications/{$application->id}/documents",
            [
                'required_document_id' => (int) $required['id'],
                'file' => FakeDocumentFile::textDisguisedAsPdf('rest-spoof.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertStatus(422);
    }
}
