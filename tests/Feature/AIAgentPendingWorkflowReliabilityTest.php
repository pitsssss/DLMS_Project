<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Enums\PendingWorkflowState;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\AIAgentActionService;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AIAgentPendingWorkflowReliabilityTest extends TestCase
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
            TestTypesSeeder::class,
            AppointmentSlotsSeeder::class,
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

    private function mockGeminiNeverCalled(): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldNotReceive('generateStructuredResponse');
        $this->instance(GeminiAgentClient::class, $mock);
    }

    private function mockGemini(): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);
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

    private function appointmentPendingApp(User $citizen, string $number): LicenseApplication
    {
        return LicenseApplication::query()->create([
            'application_number' => $number,
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::AppointmentPending->value,
        ]);
    }

    /**
     * @return array{session_id: int, token: string}
     */
    private function startStatusSelection(User $citizen): array
    {
        $this->draftApp($citizen, 'APP-REL-A');
        $this->draftApp($citizen, 'APP-REL-B');

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();

        $this->assertSame('application_selection_required', $ask->json('data.message_type'));

        return [
            'session_id' => (int) $ask->json('data.session_id'),
            'token' => (string) $ask->json('data.ui_payload.applications.0.selection_token'),
        ];
    }

    private function expireSessionWorkflow(int $sessionId): void
    {
        $session = AIAgentSession::query()->findOrFail($sessionId);
        $context = $session->context ?? [];
        $context['pending_workflow']['expires_at'] = now()->subMinute()->toIso8601String();
        $session->context = $context;
        $session->save();
    }

    public function test_expired_workflow_chat_returns_expired_not_general_help(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        ['session_id' => $sessionId] = $this->startStatusSelection($citizen);
        $this->expireSessionWorkflow($sessionId);

        $this->mockGeminiNeverCalled();
        $response = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'الأول',
        ])->assertOk();

        $this->assertSame('application_selection_expired', $response->json('data.message_type'));
        $this->assertNotSame('general_help', $response->json('data.intent'));
        $this->assertSame('get_application_status', $response->json('data.intent'));

        $session = AIAgentSession::query()->findOrFail($sessionId);
        $this->assertArrayNotHasKey('pending_workflow', $session->context ?? []);
    }

    public function test_expired_interaction_token_returns_pending_workflow_expired(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        ['session_id' => $sessionId, 'token' => $token] = $this->startStatusSelection($citizen);
        $this->expireSessionWorkflow($sessionId);

        $response = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $token,
        ])->assertStatus(422);

        $this->assertSame('PENDING_WORKFLOW_EXPIRED', $response->json('code'));
    }

    public function test_show_choices_after_expiry_returns_expired_response(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        ['session_id' => $sessionId] = $this->startStatusSelection($citizen);
        $this->expireSessionWorkflow($sessionId);

        $response = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'show_application_choices_again',
        ])->assertOk();

        $this->assertSame('application_selection_expired', $response->json('data.message_type'));
    }

    public function test_retryable_resume_failure_keeps_pending_workflow(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        ['session_id' => $sessionId, 'token' => $token] = $this->startStatusSelection($citizen);

        $calls = 0;
        $actionMock = Mockery::mock(AIAgentActionService::class);
        $actionMock->shouldReceive('executeReadOnlyNow')
            ->twice()
            ->andReturnUsing(function () use (&$calls, $citizen) {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('transient failure');
                }

                $action = AIAgentAction::query()
                    ->where('user_id', $citizen->id)
                    ->latest('id')
                    ->firstOrFail();

                return [
                    'action' => [
                        'id' => $action->id,
                        'name' => 'get_application_status',
                        'status' => 'executed',
                    ],
                    'result' => ['id' => $action->arguments['application_id']],
                    'reply' => 'حالة الطلب هي «مسودة».',
                ];
            });
        $this->instance(AIAgentActionService::class, $actionMock);

        $failed = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $token,
        ])->assertStatus(422);

        $this->assertSame('PENDING_WORKFLOW_RETRY_REQUIRED', $failed->json('code'));

        $session = AIAgentSession::query()->findOrFail($sessionId);
        $this->assertSame(
            PendingWorkflowState::AwaitingApplicationChoice->value,
            $session->context['pending_workflow']['state'] ?? null
        );

        $this->mockGemini();
        $retry = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertSame('application_status', $retry->json('data.message_type'));
        $this->assertArrayNotHasKey('pending_workflow', $session->fresh()->context ?? []);
    }

    public function test_book_appointment_selection_does_not_create_incomplete_pending_action(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApp($citizen, 'APP-BOOK-M1');
        $this->appointmentPendingApp($citizen, 'APP-BOOK-M2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'احجز أول موعد متاح',
        ])->assertOk();

        if ($ask->json('data.message_type') !== 'application_selection_required') {
            $this->markTestSkipped('Multi-application book phrase did not require application selection.');
        }

        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.applications.0.selection_token');

        $beforeActions = AIAgentAction::query()->where('session_id', $sessionId)->count();

        $selected = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertSame('appointment_slot_selection_required', $selected->json('data.message_type'));
        $this->assertSame('book_appointment', $selected->json('data.intent'));
        $this->assertFalse((bool) $selected->json('data.requires_confirmation'));
        $this->assertNull($selected->json('data.pending_action'));
        $this->assertContains('appointment_slot_choice', $selected->json('data.missing_slots'));

        $afterActions = AIAgentAction::query()->where('session_id', $sessionId)->count();
        $this->assertSame($beforeActions, $afterActions);

        $incomplete = AIAgentAction::query()
            ->where('session_id', $sessionId)
            ->where('action_name', 'book_appointment')
            ->where('requires_confirmation', true)
            ->whereNull('arguments->appointment_slot_id')
            ->count();
        $this->assertSame(0, $incomplete);

        $session = AIAgentSession::query()->findOrFail($sessionId);
        $this->assertSame(
            PendingWorkflowState::AwaitingAppointmentSlotChoice->value,
            $session->context['pending_workflow']['state'] ?? null
        );
    }

    public function test_exact_ma_badi_cancels_pending_workflow(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        ['session_id' => $sessionId] = $this->startStatusSelection($citizen);

        $cancel = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'ما بدي',
        ])->assertOk();

        $this->assertSame('application_selection_cancelled', $cancel->json('data.message_type'));
        $this->assertArrayNotHasKey(
            'pending_workflow',
            AIAgentSession::query()->findOrFail($sessionId)->context ?? []
        );
    }

    public function test_ma_badi_araaf_al_mokhalafat_switches_to_fines_not_cancel(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        ['session_id' => $sessionId, 'token' => $oldToken] = $this->startStatusSelection($citizen);

        $changed = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'ما بدي أعرف المخالفات',
        ])->assertOk();

        $this->assertSame('get_fines', $changed->json('data.intent'));
        $this->assertNotSame('application_selection_cancelled', $changed->json('data.message_type'));
        $this->assertArrayNotHasKey(
            'pending_workflow',
            AIAgentSession::query()->findOrFail($sessionId)->context ?? []
        );

        $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $oldToken,
        ])->assertStatus(422);
    }

    public function test_la_badi_show_fines_switches_topic(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        ['session_id' => $sessionId] = $this->startStatusSelection($citizen);

        $changed = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'لا بدي شوف المخالفات',
        ])->assertOk();

        $this->assertSame('get_fines', $changed->json('data.intent'));
        $this->assertNotSame('general_help', $changed->json('data.intent'));
    }

    public function test_arabic_digits_select_application_by_number_in_label(): void
    {
        $citizen = $this->citizen();
        $target = $this->draftApp($citizen, 'APP-2026-000025');
        $this->draftApp($citizen, 'APP-REL-OTHER');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        $this->mockGemini();
        $byArabic = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'الطلب رقم ٢٥',
        ])->assertOk();

        $this->assertSame('application_status', $byArabic->json('data.message_type'));
        $this->assertSame($target->id, (int) $byArabic->json('data.application.id'));
    }

    public function test_arabic_digits_only_when_not_in_candidates_re_shows_selection(): void
    {
        $citizen = $this->citizen();
        $this->draftApp($citizen, 'APP-REL-X1');
        $this->draftApp($citizen, 'APP-REL-X2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        $this->mockGeminiNeverCalled();
        $response = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => '٢٥',
        ])->assertOk();

        $this->assertSame('application_selection_required', $response->json('data.message_type'));
        $this->assertNotSame('general_help', $response->json('data.intent'));
    }

    public function test_persian_digits_select_when_application_number_matches(): void
    {
        $citizen = $this->citizen();
        $target = $this->draftApp($citizen, 'APP-2026-000025');
        $this->draftApp($citizen, 'APP-REL-P2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        $this->mockGemini();
        $response = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => '۲۵',
        ])->assertOk();

        $this->assertSame('application_status', $response->json('data.message_type'));
        $this->assertSame($target->id, (int) $response->json('data.application.id'));
    }
}
