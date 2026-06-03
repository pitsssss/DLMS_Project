<?php

namespace Tests\Feature;

use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentEvaluation;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AIAgentFlowTest extends TestCase
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

    /**
     * @param  array<string, mixed>|null  $response
     */
    private function mockGemini(?array $response, bool $throw = false): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);

        if ($throw) {
            $mock->shouldReceive('generateStructuredResponse')
                ->andThrow(new \RuntimeException('Gemini unavailable'));
        } else {
            $mock->shouldReceive('generateStructuredResponse')
                ->andReturn($response);
        }

        $this->instance(GeminiAgentClient::class, $mock);
    }

    public function test_citizen_can_send_message_and_create_session(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.91,
            'language' => 'ar',
            'reply' => 'ما نوع الرخصة التي تريدها؟',
            'missing_slots' => ['license_type'],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent', 'create_new_license_application')
            ->assertJsonPath('data.missing_slots.0', 'license_type')
            ->assertJsonPath('data.pending_action', null);

        $sessionId = (int) $response->json('data.session_id');

        $this->assertDatabaseHas('ai_agent_sessions', [
            'id' => $sessionId,
            'user_id' => $citizen->id,
            'current_intent' => 'create_new_license_application',
        ]);

        $session = AIAgentSession::query()->findOrFail($sessionId);
        $this->assertSame('create_new_license_application', $session->context['intent'] ?? null);
        $this->assertSame(['license_type'], $session->context['missing_slots'] ?? null);
        $this->assertSame('new_license', $session->context['service_type_code'] ?? null);

        $this->assertDatabaseCount('ai_agent_messages', 2);
        $this->assertEquals(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_citizen_can_continue_existing_session(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.91,
            'language' => 'ar',
            'reply' => 'ما نوع الرخصة التي تريدها؟',
            'missing_slots' => ['license_type'],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $first = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();

        $sessionId = (int) $first->json('data.session_id');

        // Simulate Gemini misclassifying a slot answer as general_help.
        $this->mockGemini([
            'intent' => 'general_help',
            'confidence' => 0.45,
            'language' => 'ar',
            'reply' => 'كيف يمكنني مساعدتك؟',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $second = $this->postJson('/api/ai-agent/message', [
            'message' => 'رخصة خاصة',
            'session_id' => $sessionId,
        ])->assertOk()
            ->assertJsonPath('data.session_id', $sessionId)
            ->assertJsonPath('data.intent', 'create_new_license_application')
            ->assertJsonPath('data.missing_slots', [])
            ->assertJsonPath('data.pending_action.name', 'create_application')
            ->assertJsonPath('data.pending_action.status', 'awaiting_confirmation')
            ->assertJsonPath('data.pending_action.arguments.license_type_code', 'private')
            ->assertJsonPath('data.pending_action.arguments.service_type_code', 'new_license');

        $reply = (string) $second->json('data.reply');
        $this->assertStringContainsString('هل تؤكد', $reply);
        $this->assertStringContainsString('رخصة قيادة خاصة', $reply);

        $this->assertDatabaseHas('ai_agent_actions', [
            'session_id' => $sessionId,
            'user_id' => $citizen->id,
            'action_name' => 'create_application',
            'status' => 'awaiting_confirmation',
        ]);
        $this->assertEquals(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
        $this->assertNotNull($second->json('data.pending_action.id'));
    }

    public function test_citizen_cannot_access_another_citizen_session(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();

        $session = AIAgentSession::query()->create([
            'user_id' => $owner->id,
            'status' => 'active',
            'context' => [],
        ]);

        Sanctum::actingAs($other);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'مرحبا',
            'session_id' => $session->id,
        ])->assertNotFound();

        $this->getJson("/api/ai-agent/sessions/{$session->id}")
            ->assertNotFound();
    }

    public function test_guest_cannot_use_ai_agent(): void
    {
        $this->postJson('/api/ai-agent/message', [
            'message' => 'hello',
        ])->assertUnauthorized();
    }

    public function test_employee_cannot_use_citizen_ai_endpoint(): void
    {
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('name', 'employee')->value('id'),
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($employee);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'hello',
        ])->assertForbidden();
    }

    public function test_validation_works(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->postJson('/api/ai-agent/message', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_create_new_license_application_asks_for_missing_license_type(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.91,
            'language' => 'ar',
            'reply' => 'ما نوع الرخصة التي تريدها؟',
            'missing_slots' => ['license_type'],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])
            ->assertOk()
            ->assertJsonPath('data.missing_slots', ['license_type'])
            ->assertJsonPath('data.pending_action', null);
    }

    public function test_complete_slots_creates_pending_action_but_does_not_execute(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $session = AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => 'active',
            'current_intent' => 'create_new_license_application',
            'context' => [
                'intent' => 'create_new_license_application',
                'missing_slots' => ['license_type'],
                'collected_slots' => [],
                'service_type_code' => 'new_license',
            ],
        ]);

        AIAgentMessage::query()->create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'بدي رخصة جديدة',
        ]);

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.94,
            'language' => 'ar',
            'reply' => 'سيتم تجهيز طلب إصدار رخصة قيادة خاصة. هل تؤكد المتابعة؟',
            'missing_slots' => [],
            'proposed_action' => [
                'name' => 'create_application',
                'arguments' => [
                    'license_type_code' => 'private',
                    'service_type_code' => 'new_license',
                ],
            ],
            'requires_confirmation' => true,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'رخصة خاصة',
            'session_id' => $session->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.pending_action.status', 'awaiting_confirmation');

        $this->assertDatabaseHas('ai_agent_actions', [
            'session_id' => $session->id,
            'status' => 'awaiting_confirmation',
        ]);
        $this->assertEquals(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
        $this->assertDatabaseMissing('ai_agent_actions', [
            'session_id' => $session->id,
            'status' => 'executed',
        ]);
    }

    public function test_admin_only_action_is_rejected(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->mockGemini([
            'intent' => 'approve_document',
            'confidence' => 0.9,
            'language' => 'ar',
            'reply' => 'سأوافق على المستند',
            'missing_slots' => [],
            'proposed_action' => [
                'name' => 'approve_document',
                'arguments' => ['application_id' => 1],
            ],
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'وافق على مستندي',
        ])
            ->assertOk()
            ->assertJsonPath('data.intent', 'admin_action_denied')
            ->assertJsonPath('data.pending_action', null);

        $this->assertEquals(0, AIAgentAction::query()->count());
    }

    public function test_out_of_scope_message_returns_safe_response(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->mockGemini([
            'intent' => 'out_of_scope',
            'confidence' => 0.9,
            'language' => 'en',
            'reply' => 'I only support driving license services.',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'What is the weather today?',
        ])
            ->assertOk()
            ->assertJsonPath('data.intent', 'out_of_scope');
    }

    public function test_gemini_failure_returns_fallback_response(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->mockGemini(null);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])
            ->assertOk()
            ->assertJsonPath('data.intent', 'create_new_license_application')
            ->assertJsonPath('data.missing_slots', ['license_type']);

        $this->assertDatabaseHas('ai_agent_evaluations', [
            'was_fallback' => true,
        ]);
    }

    public function test_gemini_exception_returns_fallback_response(): void
    {
        Sanctum::actingAs($this->citizen());
        $this->mockGemini(null, throw: true);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();

        $this->assertDatabaseHas('ai_agent_evaluations', [
            'was_fallback' => true,
            'model_used' => 'fallback',
        ]);
    }

    public function test_evaluation_row_is_created(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->mockGemini([
            'intent' => 'general_help',
            'confidence' => 0.8,
            'language' => 'ar',
            'reply' => 'كيف يمكنني مساعدتك؟',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'مرحبا',
        ])->assertOk();

        $this->assertEquals(1, AIAgentEvaluation::query()->count());
    }

    /**
     * @dataProvider licenseTypeMessageProvider
     */
    public function test_slot_answer_creates_pending_action_for_license_type_variations(
        string $message,
        string $expectedCode,
    ): void {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.91,
            'language' => 'ar',
            'reply' => 'ما نوع الرخصة التي تريدها؟',
            'missing_slots' => ['license_type'],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $first = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();

        $sessionId = (int) $first->json('data.session_id');

        $this->mockGemini([
            'intent' => 'general_help',
            'confidence' => 0.4,
            'language' => 'ar',
            'reply' => 'كيف يمكنني مساعدتك؟',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => $message,
            'session_id' => $sessionId,
        ])
            ->assertOk()
            ->assertJsonPath('data.intent', 'create_new_license_application')
            ->assertJsonPath('data.pending_action.name', 'create_application')
            ->assertJsonPath('data.pending_action.arguments.license_type_code', $expectedCode);

        $reply = (string) $response->json('data.reply');
        $this->assertStringContainsString('هل تؤكد', $reply);

        $this->assertEquals(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function licenseTypeMessageProvider(): array
    {
        return [
            'private phrase' => ['رخصة خاصة', 'private'],
            'private word' => ['خاصة', 'private'],
            'public word' => ['عامة', 'public'],
            'truck word' => ['شاحنة', 'truck'],
            'bus word' => ['حافلة', 'bus'],
        ];
    }

    public function test_ai_agent_blocks_duplicate_create_application_when_draft_exists(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-EXISTING-DRAFT',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.91,
            'language' => 'ar',
            'reply' => 'ما نوع الرخصة؟',
            'missing_slots' => ['license_type'],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $first = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();

        $sessionId = (int) $first->json('data.session_id');

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.94,
            'language' => 'ar',
            'reply' => 'هل تؤكد؟',
            'missing_slots' => [],
            'proposed_action' => [
                'name' => 'create_application',
                'arguments' => [
                    'license_type_code' => 'private',
                    'service_type_code' => 'new_license',
                ],
            ],
            'requires_confirmation' => true,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $second = $this->postJson('/api/ai-agent/message', [
            'message' => 'رخصة خاصة',
            'session_id' => $sessionId,
        ])->assertOk();

        $this->assertNotEquals('create_application', $second->json('data.pending_action.name'));
        $this->assertEquals('get_application_status', $second->json('data.pending_action.name'));
        $this->assertStringContainsString('قيد المتابعة', (string) $second->json('data.reply'));
        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_ai_agent_allows_different_license_type_when_private_draft_exists(): void
    {
        $citizen = $this->citizen();
        $private = LicenseType::query()->where('code', 'private')->firstOrFail();
        $truck = LicenseType::query()->where('code', 'truck')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-PRIVATE-ONLY',
            'citizen_id' => $citizen->id,
            'license_type_id' => $private->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);

        $this->mockGemini(null);

        $session = AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => 'active',
            'context' => [
                'intent' => 'create_new_license_application',
                'collected_slots' => ['license_type_code' => 'truck'],
                'service_type_code' => 'new_license',
            ],
        ]);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شاحنة',
            'session_id' => $session->id,
        ])->assertOk();

        $this->assertEquals('create_application', $response->json('data.pending_action.name'));
        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_affirmative_message_confirms_awaiting_action_via_chat(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.91,
            'language' => 'ar',
            'reply' => 'ما نوع الرخصة؟',
            'missing_slots' => ['license_type'],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $first = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();

        $sessionId = (int) $first->json('data.session_id');

        $this->mockGemini([
            'intent' => 'general_help',
            'confidence' => 0.45,
            'language' => 'ar',
            'reply' => 'كيف يمكنني مساعدتك؟',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);

        $second = $this->postJson('/api/ai-agent/message', [
            'message' => 'رخصة خاصة',
            'session_id' => $sessionId,
        ])->assertOk();

        $actionId = (int) $second->json('data.pending_action.id');
        $this->assertEquals(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());

        $third = $this->postJson('/api/ai-agent/message', [
            'message' => 'نعم اؤكد',
            'session_id' => $sessionId,
        ])->assertOk()
            ->assertJsonPath('data.action_confirmed', true)
            ->assertJsonPath('data.executed_action.status', 'executed')
            ->assertJsonPath('data.pending_action', null)
            ->assertJsonPath('data.result.status', 'draft');

        $this->assertStringContainsString('تم إنشاء طلب', (string) $third->json('data.reply'));
        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());

        $this->assertDatabaseHas('ai_agent_actions', [
            'id' => $actionId,
            'status' => 'executed',
        ]);

        $this->assertEquals(0, AIAgentAction::query()
            ->where('session_id', $sessionId)
            ->where('status', 'awaiting_confirmation')
            ->count());
    }

    public function test_citizen_can_list_and_show_sessions(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $session = AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => 'active',
            'context' => [],
            'last_message_at' => now(),
        ]);

        AIAgentMessage::query()->create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'test',
        ]);

        $this->getJson('/api/ai-agent/sessions')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $session->id);

        $this->getJson("/api/ai-agent/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.messages.0.content', 'test');
    }
}
