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
use App\Modules\AIAgent\Enums\DocumentFlowState;
use App\Modules\AIAgent\Models\AIAgentAction;
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

class AIAgentConversationalDocumentFlowTest extends TestCase
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

    private function createDraftApplication(User $citizen, string $number = 'APP-CONV-1'): LicenseApplication
    {
        return LicenseApplication::query()->create([
            'application_number' => $number,
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::Draft->value,
        ]);
    }

    private function mockGemini(): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);
    }

    private function askRequiredDocuments(User $citizen, ?int $sessionId = null)
    {
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $payload = ['message' => 'شو الوثائق المطلوبة؟'];
        if ($sessionId !== null) {
            $payload['session_id'] = $sessionId;
        }

        return $this->postJson('/api/ai-agent/message', $payload);
    }

    private function interact(int $sessionId, string $action, ?string $selectionToken = null)
    {
        $payload = ['action' => $action];
        if ($selectionToken !== null) {
            $payload['selection_token'] = $selectionToken;
        }

        return $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", $payload);
    }

    public function test_single_eligible_application_is_resolved_automatically_with_upload_offer(): void
    {
        $citizen = $this->citizen();
        $application = $this->createDraftApplication($citizen);

        $response = $this->askRequiredDocuments($citizen)->assertOk();

        $this->assertSame('document_upload_offer', $response->json('data.message_type'));
        $this->assertStringContainsString('الوثائق المطلوبة', (string) $response->json('data.reply'));
        $this->assertStringContainsString('قسم مراجعة الوثائق', (string) $response->json('data.reply'));
        $this->assertSame($application->id, (int) $response->json('data.application.id'));
        $this->assertNotEmpty($response->json('data.ui_payload.buttons'));
        $this->assertSame('choose_agent_document_upload', $response->json('data.ui_payload.buttons.0.action'));
    }

    public function test_multiple_eligible_applications_require_selection(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen, 'APP-CONV-A');
        $this->createDraftApplication($citizen, 'APP-CONV-B');

        $response = $this->askRequiredDocuments($citizen)->assertOk();

        $this->assertSame('application_selection_required', $response->json('data.message_type'));
        $this->assertCount(2, $response->json('data.ui_payload.applications'));
        $this->assertNotEmpty($response->json('data.ui_payload.applications.0.selection_token'));
    }

    public function test_application_selection_token_from_other_citizen_is_rejected(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();
        $this->createDraftApplication($owner, 'APP-CONV-OWN');
        $this->createDraftApplication($owner, 'APP-CONV-OWN-2');

        $ownerResponse = $this->askRequiredDocuments($owner)->assertOk();
        $token = (string) $ownerResponse->json('data.ui_payload.applications.0.selection_token');
        $sessionId = (int) $ownerResponse->json('data.session_id');

        Sanctum::actingAs($other);
        $this->interact($sessionId, 'select_application', $token)->assertStatus(404);
    }

    public function test_no_eligible_application_returns_formal_reply(): void
    {
        $citizen = $this->citizen();

        $response = $this->askRequiredDocuments($citizen)->assertOk();

        $this->assertSame('document_flow_error', $response->json('data.message_type'));
        $this->assertStringContainsString('لا يوجد', (string) $response->json('data.reply'));
    }

    public function test_agent_upload_consent_is_recorded_and_shows_document_buttons(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen);
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sessionId = (int) $offer->json('data.session_id');

        $response = $this->interact($sessionId, 'choose_agent_document_upload')->assertOk();

        $this->assertSame('required_document_selection', $response->json('data.message_type'));
        $this->assertNotEmpty($response->json('data.ui_payload.documents'));
        $this->assertArrayHasKey('selection_token', $response->json('data.ui_payload.documents.0'));
        $this->assertArrayNotHasKey('id', $response->json('data.ui_payload.documents.0'));

        $session = AIAgentSession::query()->findOrFail($sessionId);
        $flow = $session->context['document_flow'] ?? [];
        $this->assertTrue((bool) ($flow['auto_submit_on_completion'] ?? false));
        $this->assertNotEmpty($flow['submission_consent_at'] ?? null);
        $this->assertSame('agent_upload_offer', $flow['submission_consent_source'] ?? null);
    }

    public function test_manual_upload_guidance_does_not_create_upload_token_or_submit(): void
    {
        $citizen = $this->citizen();
        $application = $this->createDraftApplication($citizen);
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sessionId = (int) $offer->json('data.session_id');

        $response = $this->interact($sessionId, 'choose_manual_document_upload')->assertOk();

        $this->assertSame('manual_document_upload_guidance', $response->json('data.message_type'));
        $this->assertSame('application_documents', $response->json('data.ui_payload.navigation_target.screen'));
        $this->assertSame($application->id, (int) $response->json('data.ui_payload.navigation_target.params.application_id'));

        $session = AIAgentSession::query()->findOrFail($sessionId);
        $flow = $session->context['document_flow'] ?? [];
        $this->assertSame(DocumentFlowState::ManualUploadSelected->value, $flow['state'] ?? null);
        $this->assertFalse((bool) ($flow['auto_submit_on_completion'] ?? false));
        $this->assertEmpty($flow['upload_token_hash'] ?? null);
        $this->assertSame(0, AIAgentAction::query()->where('action_name', 'submit_documents_for_review')->count());
        $application->refresh();
        $this->assertSame(ApplicationStatus::Draft, $application->status);
    }

    public function test_textual_yes_and_no_work_only_in_upload_offer_state(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen);
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sessionId = (int) $offer->json('data.session_id');

        Sanctum::actingAs($citizen);
        $this->mockGemini();
        $yes = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'نعم',
        ])->assertOk();
        $this->assertSame('required_document_selection', $yes->json('data.message_type'));

        // Outside offer state, plain "نعم" must not restart the document upload path.
        $this->mockGemini();
        $outside = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'نعم',
        ])->assertOk();
        $this->assertNotSame('document_upload_offer', $outside->json('data.message_type'));
        $this->assertNotSame('required_document_selection', $outside->json('data.message_type'));
    }

    public function test_textual_no_selects_manual_upload(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen);
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sessionId = (int) $offer->json('data.session_id');

        Sanctum::actingAs($citizen);
        $this->mockGemini();
        $no = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'لا، برفعهن لحالي',
        ])->assertOk();

        $this->assertSame('manual_document_upload_guidance', $no->json('data.message_type'));
    }

    public function test_select_document_issues_one_time_upload_token_without_ids_for_flutter(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen, 'APP-CONV-TOKEN');
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sid = (int) $offer->json('data.session_id');
        $docsResponse = $this->interact($sid, 'choose_agent_document_upload')->assertOk();
        $token = (string) $docsResponse->json('data.ui_payload.documents.0.selection_token');

        $fileRequired = $this->interact($sid, 'select_required_document', $token)->assertOk();
        $this->assertSame('file_upload_required', $fileRequired->json('data.message_type'));
        $this->assertNotEmpty($fileRequired->json('data.ui_payload.upload_token'));
        $this->assertSame(1, (int) $fileRequired->json('data.ui_payload.maximum_files'));
        $this->assertArrayNotHasKey('application_id', $fileRequired->json('data.ui_payload'));
        $this->assertArrayNotHasKey('required_document_id', $fileRequired->json('data.ui_payload'));

        $session = AIAgentSession::query()->findOrFail($sid);
        $flow = $session->context['document_flow'] ?? [];
        $this->assertSame(DocumentFlowState::AwaitingFile->value, $flow['state'] ?? null);
        $this->assertNotEmpty($flow['upload_token_hash'] ?? null);
        $this->assertSame('active', $flow['upload_token_status'] ?? null);
        $this->assertStringNotContainsString(
            (string) $fileRequired->json('data.ui_payload.upload_token'),
            json_encode($session->context)
        );
    }

    public function test_exactly_one_file_enforced_and_token_not_consumed_on_multi_file(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        $this->createDraftApplication($citizen, 'APP-CONV-MULTI');
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sid = (int) $offer->json('data.session_id');
        $docs = $this->interact($sid, 'choose_agent_document_upload')->assertOk();
        $selectionToken = (string) $docs->json('data.ui_payload.documents.0.selection_token');
        $label = (string) $docs->json('data.ui_payload.documents.0.label');
        $fileRequired = $this->interact($sid, 'select_required_document', $selectionToken)->assertOk();
        $uploadToken = (string) $fileRequired->json('data.ui_payload.upload_token');

        $beforeCount = ApplicationDocument::query()->count();

        $response = $this->call(
            'POST',
            "/api/ai-agent/sessions/{$sid}/documents",
            ['upload_token' => $uploadToken],
            [],
            [
                'file' => FakeDocumentFile::pdf('one.pdf'),
                'files' => [FakeDocumentFile::pdf('two.pdf')],
            ],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertStatus(422);
        $this->assertSame('EXACTLY_ONE_DOCUMENT_FILE_REQUIRED', $response->json('code'));
        $this->assertSame($label, $response->json('data.selected_document.label'));
        $this->assertSame(2, (int) $response->json('data.received_files_count'));
        $this->assertTrue((bool) $response->json('data.upload_token_still_valid'));
        $this->assertSame($beforeCount, ApplicationDocument::query()->count());

        $session = AIAgentSession::query()->findOrFail($sid);
        $flow = $session->context['document_flow'] ?? [];
        $this->assertSame(DocumentFlowState::AwaitingFile->value, $flow['state'] ?? null);
        $this->assertSame('active', $flow['upload_token_status'] ?? null);

        // Retry with exactly one file succeeds.
        $ok = $this->post(
            "/api/ai-agent/sessions/{$sid}/documents",
            [
                'upload_token' => $uploadToken,
                'file' => FakeDocumentFile::pdf('retry.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->assertSame('document_uploaded', $ok->json('data.message_type'));
        $this->assertSame($beforeCount + 1, ApplicationDocument::query()->count());
    }

    public function test_zero_files_rejected_with_stable_code(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen, 'APP-CONV-ZERO');
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sid = (int) $offer->json('data.session_id');
        $docs = $this->interact($sid, 'choose_agent_document_upload')->assertOk();
        $fileRequired = $this->interact(
            $sid,
            'select_required_document',
            (string) $docs->json('data.ui_payload.documents.0.selection_token')
        )->assertOk();

        $this->postJson("/api/ai-agent/sessions/{$sid}/documents", [
            'upload_token' => $fileRequired->json('data.ui_payload.upload_token'),
        ])->assertStatus(422)->assertJsonPath('code', 'DOCUMENT_FILE_REQUIRED');
    }

    public function test_full_conversational_flow_auto_submits_to_shared_review_queue(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        $application = $this->createDraftApplication($citizen, 'APP-CONV-FULL');
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sid = (int) $offer->json('data.session_id');
        $docs = $this->interact($sid, 'choose_agent_document_upload')->assertOk();

        $remaining = $docs->json('data.ui_payload.documents');
        $this->assertNotEmpty($remaining);

        $lastResponse = null;
        while (! empty($remaining)) {
            $selectionToken = (string) $remaining[0]['selection_token'];
            $fileRequired = $this->interact($sid, 'select_required_document', $selectionToken)->assertOk();
            $uploadToken = (string) $fileRequired->json('data.ui_payload.upload_token');

            $lastResponse = $this->post(
                "/api/ai-agent/sessions/{$sid}/documents",
                [
                    'upload_token' => $uploadToken,
                    'file' => FakeDocumentFile::pdf('doc.pdf'),
                ],
                ['Accept' => 'application/json']
            )->assertOk();

            $remaining = $lastResponse->json('data.ui_payload.remaining_documents') ?? [];
            if ($lastResponse->json('data.message_type') === 'documents_submitted_for_review') {
                break;
            }
        }

        $this->assertNotNull($lastResponse);
        $this->assertSame('documents_submitted_for_review', $lastResponse->json('data.message_type'));
        $this->assertTrue((bool) $lastResponse->json('data.ui_payload.submitted_for_review'));
        $this->assertSame('documents_under_review', $lastResponse->json('data.application.status'));
        $this->assertStringContainsString('قسم مراجعة الوثائق', (string) $lastResponse->json('data.reply'));
        $this->assertStringNotContainsString('آدمن', (string) $lastResponse->json('data.reply'));

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsUnderReview, $application->status);

        $action = AIAgentAction::query()
            ->where('session_id', $sid)
            ->where('action_name', 'submit_documents_for_review')
            ->latest('id')
            ->first();
        $this->assertNotNull($action);
        $this->assertSame('upfront_document_flow_consent', $action->arguments['confirmation_source'] ?? null);

        $this->assertSame(1, Notification::query()->where('user_id', $citizen->id)->count());

        $queue = app(DashboardDocumentReviewService::class)->paginate([
            'application_id' => $application->id,
        ], 20);
        $this->assertGreaterThanOrEqual(1, $queue->total());
    }

    public function test_manual_mode_does_not_auto_submit_after_legacy_uploads(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        $application = $this->createDraftApplication($citizen, 'APP-CONV-MANUAL');
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sid = (int) $offer->json('data.session_id');
        $this->interact($sid, 'choose_manual_document_upload')->assertOk();

        Sanctum::actingAs($citizen);
        $checklist = $this->getJson("/api/applications/{$application->id}/required-documents")->assertOk()->json('data');
        foreach ($checklist as $required) {
            $this->post(
                "/api/applications/{$application->id}/documents",
                [
                    'required_document_id' => (int) $required['id'],
                    'file' => FakeDocumentFile::pdf('manual.pdf'),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }

        $application->refresh();
        $this->assertSame(ApplicationStatus::Draft, $application->status);
        $this->assertSame(0, AIAgentAction::query()->where('action_name', 'submit_documents_for_review')->count());
    }

    public function test_tampered_selection_token_is_rejected(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen, 'APP-CONV-TAMPER');
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sid = (int) $offer->json('data.session_id');
        $docs = $this->interact($sid, 'choose_agent_document_upload')->assertOk();
        $token = (string) $docs->json('data.ui_payload.documents.0.selection_token');
        $tampered = substr($token, 0, -4).'xxxx';

        $this->interact($sid, 'select_required_document', $tampered)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_SELECTION_TOKEN');
    }

    public function test_legacy_agent_upload_with_ids_still_works(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        $application = $this->createDraftApplication($citizen, 'APP-CONV-LEGACY');
        $session = AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => AgentSessionStatus::Active,
            'context' => [],
            'last_message_at' => now(),
        ]);

        Sanctum::actingAs($citizen);
        $checklist = $this->getJson("/api/applications/{$application->id}/required-documents")->assertOk()->json('data');
        $requiredId = (int) $checklist[0]['id'];

        $this->post(
            "/api/ai-agent/sessions/{$session->id}/documents",
            [
                'application_id' => $application->id,
                'required_document_id' => $requiredId,
                'file' => FakeDocumentFile::pdf('legacy.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->assertDatabaseHas('application_documents', [
            'application_id' => $application->id,
            'required_document_id' => $requiredId,
            'status' => DocumentStatus::PendingReview->value,
        ]);
    }

    public function test_upload_token_rejects_id_mismatch_in_hybrid_request(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        $application = $this->createDraftApplication($citizen, 'APP-CONV-MISMATCH');
        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sid = (int) $offer->json('data.session_id');
        $docs = $this->interact($sid, 'choose_agent_document_upload')->assertOk();
        $fileRequired = $this->interact(
            $sid,
            'select_required_document',
            (string) $docs->json('data.ui_payload.documents.0.selection_token')
        )->assertOk();

        $boundRequiredId = (int) (AIAgentSession::query()->findOrFail($sid)->context['document_flow']['required_document_id'] ?? 0);
        $otherRequired = RequiredDocument::query()
            ->where('is_active', true)
            ->whereKeyNot($boundRequiredId)
            ->value('id');

        $this->post(
            "/api/ai-agent/sessions/{$sid}/documents",
            [
                'upload_token' => $fileRequired->json('data.ui_payload.upload_token'),
                'application_id' => $application->id,
                'required_document_id' => (int) ($otherRequired ?: 999999),
                'file' => FakeDocumentFile::pdf('bad.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertStatus(422)->assertJsonPath('code', 'INVALID_UPLOAD_TOKEN');
    }

    public function test_approved_document_is_not_offered_for_upload(): void
    {
        Storage::fake('local');

        $citizen = $this->citizen();
        $application = $this->createDraftApplication($citizen, 'APP-CONV-APPROVED');
        Sanctum::actingAs($citizen);
        $checklist = $this->getJson("/api/applications/{$application->id}/required-documents")->assertOk()->json('data');
        $first = $checklist[0];

        $this->post(
            "/api/applications/{$application->id}/documents",
            [
                'required_document_id' => (int) $first['id'],
                'file' => FakeDocumentFile::pdf('first.pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        ApplicationDocument::query()
            ->where('application_id', $application->id)
            ->where('required_document_id', (int) $first['id'])
            ->update(['status' => DocumentStatus::Approved->value]);

        $offer = $this->askRequiredDocuments($citizen)->assertOk();
        $sid = (int) $offer->json('data.session_id');
        $docs = $this->interact($sid, 'choose_agent_document_upload')->assertOk();
        $labels = collect($docs->json('data.ui_payload.documents'))->pluck('label')->all();
        $this->assertNotContains($first['name'], $labels);
    }
}
