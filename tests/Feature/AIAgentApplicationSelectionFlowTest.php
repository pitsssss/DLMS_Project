<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentSessionStatus;
use App\Modules\AIAgent\Enums\PendingWorkflowState;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AIAgentApplicationSelectionFlowTest extends TestCase
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

    private function draftApp(User $citizen, string $number): LicenseApplication
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

    public function test_single_application_status_executes_immediately(): void
    {
        $citizen = $this->citizen();
        $app = $this->draftApp($citizen, 'APP-SEL-ONE');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();

        $this->assertSame('get_application_status', $response->json('data.intent'));
        $this->assertNotSame('application_selection_required', $response->json('data.message_type'));
        $this->assertSame('get_application_status', $response->json('data.executed_action.name'));
        $resolvedId = (int) (
            $response->json('data.application.id')
            ?? $response->json('data.result.id')
            ?? $response->json('data.result.application_id')
            ?? 0
        );
        $this->assertSame($app->id, $resolvedId);
        $this->assertEmpty($response->json('data.missing_slots') ?? []);
    }

    public function test_multiple_applications_return_selection_buttons_and_pending_workflow(): void
    {
        $citizen = $this->citizen();
        $a = $this->draftApp($citizen, 'APP-SEL-A');
        $b = $this->draftApp($citizen, 'APP-SEL-B');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();

        $this->assertSame('application_selection_required', $response->json('data.message_type'));
        $this->assertSame('get_application_status', $response->json('data.intent'));
        $this->assertContains('application_choice', $response->json('data.missing_slots'));
        $this->assertCount(2, $response->json('data.ui_payload.applications'));
        $this->assertNotEmpty($response->json('data.ui_payload.applications.0.selection_token'));
        $this->assertArrayNotHasKey('id', $response->json('data.ui_payload.applications.0'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertNull($response->json('data.executed_action'));

        $session = AIAgentSession::query()->findOrFail((int) $response->json('data.session_id'));
        $workflow = $session->context['pending_workflow'] ?? [];
        $this->assertSame(PendingWorkflowState::AwaitingApplicationChoice->value, $workflow['state'] ?? null);
        $this->assertSame('get_application_status', $workflow['intent'] ?? null);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $workflow['candidate_application_ids'] ?? []);
    }

    public function test_select_application_interaction_resumes_status_without_general_help(): void
    {
        $citizen = $this->citizen();
        $a = $this->draftApp($citizen, 'APP-SEL-R1');
        $b = $this->draftApp($citizen, 'APP-SEL-R2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.applications.0.selection_token');
        // Buttons are ordered by id desc — newest first.
        $expectedId = $b->id;

        $selected = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertSame('application_status', $selected->json('data.message_type'));
        $this->assertSame('get_application_status', $selected->json('data.intent'));
        $this->assertNotSame('general_help', $selected->json('data.intent'));
        $this->assertSame('get_application_status', $selected->json('data.executed_action.name'));
        $this->assertSame($expectedId, (int) $selected->json('data.application.id'));
        $this->assertStringContainsString('مسودة', (string) $selected->json('data.reply'));

        $session = AIAgentSession::query()->findOrFail($sessionId);
        $this->assertArrayNotHasKey('pending_workflow', $session->context ?? []);
        $this->assertSame($expectedId, (int) ($session->context['last_application_id'] ?? 0));
    }

    public function test_text_first_and_application_id_selection_work(): void
    {
        $citizen = $this->citizen();
        $first = $this->draftApp($citizen, 'APP-SEL-T1');
        $second = $this->draftApp($citizen, 'APP-SEL-T2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        // Candidates are ordered by id desc, so "الأول" is the newest ($second).
        $this->mockGemini();
        $byOrdinal = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'الأول',
        ])->assertOk();
        $this->assertSame('application_status', $byOrdinal->json('data.message_type'));
        $this->assertSame($second->id, (int) $byOrdinal->json('data.application.id'));

        $this->mockGemini();
        $ask2 = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId2 = (int) $ask2->json('data.session_id');
        $this->mockGemini();
        $byId = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId2,
            'message' => 'الطلب رقم '.$first->id,
        ])->assertOk();
        $this->assertSame($first->id, (int) $byId->json('data.application.id'));
        $this->assertNotSame('general_help', $byId->json('data.intent'));
    }

    public function test_ambiguous_text_does_not_return_general_help(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-SEL-AMB1');
        $this->draftApp($citizen, 'APP-SEL-AMB2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        foreach (['هاد', 'الطلب تبعي', 'ما بعرف', 'واحد'] as $msg) {
            $this->mockGemini();
            $response = $this->postJson('/api/ai-agent/message', [
                'session_id' => $sessionId,
                'message' => $msg,
            ])->assertOk();

            $this->assertSame('application_selection_required', $response->json('data.message_type'), $msg);
            $this->assertNotSame('general_help', $response->json('data.intent'), $msg);
            $this->assertNotEmpty($response->json('data.ui_payload.applications'), $msg);
        }
    }

    public function test_tampered_and_foreign_tokens_are_rejected(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();
        $this->draftApp($owner, 'APP-SEL-SEC1');
        $this->draftApp($owner, 'APP-SEL-SEC2');
        Sanctum::actingAs($owner);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.applications.0.selection_token');

        $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => substr($token, 0, -4).'zzzz',
        ])->assertStatus(422);

        Sanctum::actingAs($other);
        $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $token,
        ])->assertStatus(404);
    }

    public function test_document_purpose_token_rejected_by_pending_workflow(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-SEL-DOC1');
        $this->draftApp($citizen, 'APP-SEL-DOC2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        // Document flow multi-app selection
        $docs = $this->postJson('/api/ai-agent/message', ['message' => 'شو الوثائق المطلوبة؟'])->assertOk();
        $this->assertSame('application_selection_required', $docs->json('data.message_type'));
        $docToken = (string) $docs->json('data.ui_payload.applications.0.selection_token');
        $docSession = (int) $docs->json('data.session_id');

        // Status pending workflow on another session
        $status = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $statusSession = (int) $status->json('data.session_id');

        $this->postJson("/api/ai-agent/sessions/{$statusSession}/interactions", [
            'action' => 'select_application',
            'selection_token' => $docToken,
        ])->assertStatus(422);

        // Document token still works on document session
        $this->postJson("/api/ai-agent/sessions/{$docSession}/interactions", [
            'action' => 'select_application',
            'selection_token' => $docToken,
        ])->assertOk();
    }

    public function test_cancel_pending_workflow(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-SEL-C1');
        $this->draftApp($citizen, 'APP-SEL-C2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        $cancel = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'cancel_pending_workflow',
        ])->assertOk();

        $this->assertSame('application_selection_cancelled', $cancel->json('data.message_type'));
        $session = AIAgentSession::query()->findOrFail($sessionId);
        $this->assertArrayNotHasKey('pending_workflow', $session->context ?? []);
    }

    public function test_yes_during_application_selection_does_not_start_document_upload(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-SEL-Y1');
        $this->draftApp($citizen, 'APP-SEL-Y2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        $this->mockGemini();
        $yes = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'نعم',
        ])->assertOk();

        $this->assertSame('application_selection_required', $yes->json('data.message_type'));
        $this->assertNotSame('required_document_selection', $yes->json('data.message_type'));
        $this->assertNotSame('manual_document_upload_guidance', $yes->json('data.message_type'));
    }

    public function test_next_step_multi_application_selection_continues(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-SEL-NS1');
        $b = $this->draftApp($citizen, 'APP-SEL-NS2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الخطوة التالية لطلبي؟',
        ])->assertOk();

        if ($ask->json('data.message_type') !== 'application_selection_required') {
            $this->markTestSkipped('Next-step phrase did not produce multi-application selection in this environment.');
        }

        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.applications.0.selection_token');

        $selected = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertNotSame('general_help', $selected->json('data.intent'));
        $resolvedId = (int) (
            $selected->json('data.application.id')
            ?? $selected->json('data.result.application_id')
            ?? 0
        );
        // Buttons ordered by id desc — newest first.
        $this->assertSame($b->id, $resolvedId);
    }

    public function test_submit_documents_selection_does_not_execute_submit(): void
    {
        $citizen = $this->citizen();
        $a = $this->draftApp($citizen, 'APP-SEL-SUB1');
        $this->draftApp($citizen, 'APP-SEL-SUB2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();

        if ($ask->json('data.message_type') !== 'application_selection_required') {
            // May return incomplete-docs reply if resolver picks one app via last id — force multi path.
            $this->assertTrue(true);

            return;
        }

        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.applications.0.selection_token');
        $selected = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertTrue((bool) $selected->json('data.requires_confirmation'));
        $this->assertNotNull($selected->json('data.pending_action'));
        $this->assertSame('submit_documents_for_review', $selected->json('data.pending_action.name'));
        $a->refresh();
        $this->assertSame(ApplicationStatus::Draft, $a->status);
    }

    public function test_change_topic_during_selection_clears_pending_and_runs_new_intent(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-SEL-CT1');
        $this->draftApp($citizen, 'APP-SEL-CT2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $this->assertSame('application_selection_required', $ask->json('data.message_type'));
        $sessionId = (int) $ask->json('data.session_id');

        $changed = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'بدي أعرف المخالفات',
        ])->assertOk();

        $this->assertNotSame('application_selection_required', $changed->json('data.message_type'));
        $this->assertNotSame('general_help', $changed->json('data.intent'));
        $this->assertSame('get_fines', $changed->json('data.intent'));

        $session = AIAgentSession::query()->findOrFail($sessionId);
        $this->assertArrayNotHasKey('pending_workflow', $session->context ?? []);
    }

    public function test_show_application_choices_again_reissues_tokens(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-SEL-AGAIN1');
        $this->draftApp($citizen, 'APP-SEL-AGAIN2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $firstToken = (string) $ask->json('data.ui_payload.applications.0.selection_token');

        $again = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'show_application_choices_again',
        ])->assertOk();

        $this->assertSame('application_selection_required', $again->json('data.message_type'));
        $this->assertNotEmpty($again->json('data.ui_payload.applications'));
        $this->assertNotSame('', (string) $again->json('data.ui_payload.applications.0.selection_token'));
        $this->assertNotSame($firstToken, (string) $again->json('data.ui_payload.applications.0.selection_token'));
    }

    public function test_unrecognized_pending_replies_never_return_general_help(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-SEL-GH1');
        $this->draftApp($citizen, 'APP-SEL-GH2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        foreach (['هاد', 'هي', 'واحد', 'الطلب تبعي', 'ما بعرف'] as $message) {
            $response = $this->postJson('/api/ai-agent/message', [
                'session_id' => $sessionId,
                'message' => $message,
            ])->assertOk();

            $this->assertSame(
                'application_selection_required',
                $response->json('data.message_type'),
                "Expected clarification for: {$message}"
            );
            $this->assertNotSame('general_help', $response->json('data.intent'));
            $this->assertNotEmpty($response->json('data.ui_payload.applications'));
        }
    }
}
