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
use Database\Seeders\RequiredDocumentsSeeder;
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
        $employee = User::factory()->dashboardEmployee('employee')->create();

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
        $this->assertStringContainsString('قيد المتابعة', (string) $second->json('data.reply'));
        $this->assertStringNotContainsString('messages.', (string) $second->json('data.reply'));
        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());

        $this->assertEquals('get_application_status', $second->json('data.pending_action.name'));
        $this->assertEquals('get_application_status', $second->json('data.intent'));
        $this->assertTrue((bool) $second->json('data.requires_confirmation'));
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

    public function test_intent_switches_from_create_flow_to_application_status(): void
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
            'intent' => 'create_new_license_application',
            'confidence' => 0.94,
            'language' => 'ar',
            'reply' => 'سأنشئ طلب رخصة خاصة',
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
            'session_id' => $sessionId,
            'message' => 'وين صار حالة الطلب الخاص بي؟',
        ])->assertOk();

        $this->assertEquals('get_application_status', $second->json('data.intent'));
        $this->assertNotEquals('create_new_license_application', $second->json('data.intent'));
        $this->assertNotEquals('create_application', $second->json('data.pending_action.name'));
        $this->assertStringNotContainsString('messages.', (string) $second->json('data.reply'));
    }

    public function test_possessive_phrase_is_not_extracted_as_private_license_type(): void
    {
        $this->assertNull(
            \App\Modules\AIAgent\Support\LicenseTypeSlotExtractor::extract('وين صار حالة الطلب الخاص بي؟')
        );
        $this->assertNull(
            \App\Modules\AIAgent\Support\LicenseTypeSlotExtractor::extract('الطلب الخاص بي')
        );
        $this->assertEquals(
            'private',
            \App\Modules\AIAgent\Support\LicenseTypeSlotExtractor::extract('رخصة خاصة')
        );
    }

    public function test_raw_translation_key_in_gemini_reply_is_localized_for_duplicate(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-KEY-LEAK',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);

        $session = AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => 'active',
            'current_intent' => 'create_new_license_application',
            'context' => [
                'intent' => 'create_new_license_application',
                'collected_slots' => ['license_type_code' => 'private'],
                'service_type_code' => 'new_license',
            ],
        ]);

        $this->mockGemini([
            'intent' => 'create_new_license_application',
            'confidence' => 0.95,
            'language' => 'ar',
            'reply' => 'messages.ai_agent.existing_active_application',
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

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'رخصة خاصة',
            'session_id' => $session->id,
        ])->assertOk();

        $reply = (string) $response->json('data.reply');
        $this->assertStringNotContainsString('messages.ai_agent', $reply);
        $this->assertStringContainsString('قيد المتابعة', $reply);
    }

    public function test_status_query_with_one_active_application_returns_status(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-STATUS-ONE',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'وين صار حالة الطلب الخاص بي؟',
        ])->assertOk();

        $this->assertEquals('get_application_status', $response->json('data.intent'));
        $this->assertStringContainsString('APP-STATUS-ONE', (string) $response->json('data.reply'));
        $this->assertStringContainsString('مسودة', (string) $response->json('data.reply'));
        $this->assertStringNotContainsString('هي: draft', (string) $response->json('data.reply'));
        $this->assertFalse((bool) $response->json('data.requires_confirmation'));
    }

    public function test_status_query_with_no_applications_returns_arabic_message(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();

        $this->assertEquals('get_application_status', $response->json('data.intent'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertStringContainsString('لا يوجد', (string) $response->json('data.reply'));
    }

    public function test_status_query_with_multiple_applications_asks_to_choose(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        foreach (['APP-MULTI-1', 'APP-MULTI-2'] as $number) {
            LicenseApplication::query()->create([
                'application_number' => $number,
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'status' => 'draft',
            ]);
        }

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'تابعلي طلبي',
        ])->assertOk();

        $this->assertEquals('get_application_status', $response->json('data.intent'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertStringContainsString('أكثر من طلب', (string) $response->json('data.reply'));
        $this->assertContains('application_choice', $response->json('data.missing_slots'));
    }

    public function test_next_step_after_status_in_same_session(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-NEXT-FLOW',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $first = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();

        $sessionId = (int) $first->json('data.session_id');
        $this->assertEquals('get_application_status', $first->json('data.intent'));
        $this->assertStringContainsString('مسودة', (string) $first->json('data.reply'));
        $this->assertStringNotContainsString('هي: draft', (string) $first->json('data.reply'));

        $second = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو الخطوة اللي بعدها؟',
        ])->assertOk();

        $this->assertEquals('get_application_next_step', $second->json('data.intent'));
        $reply = (string) $second->json('data.reply');
        $this->assertStringContainsString('مسودة', $reply);
        $this->assertStringContainsString('رفع الوثائق', $reply);
        $this->assertStringNotContainsString('messages.', $reply);
        $this->assertStringNotContainsString('application_next_step.', $reply);
        $this->assertEquals('draft', $second->json('data.result.next_step_key'));
        $nextStepMessage = (string) $second->json('data.result.next_step_message');
        $this->assertStringContainsString('رفع الوثائق', $nextStepMessage);
        $this->assertStringNotContainsString('messages.', $nextStepMessage);
        $this->assertNotEquals('general_help', $second->json('data.intent'));
        $this->assertNotEquals('create_application', $second->json('data.pending_action.name'));
        $this->assertFalse((bool) $second->json('data.requires_confirmation'));
    }

    public function test_draft_next_step_is_translated_when_app_locale_is_english(): void
    {
        app()->setLocale('en');

        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-LOCALE-EN',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الخطوة اللي بعدها؟',
        ])->assertOk();

        $reply = (string) $response->json('data.reply');
        $this->assertEquals('get_application_next_step', $response->json('data.intent'));
        $this->assertStringContainsString('المسودة', $reply);
        $this->assertStringContainsString('رفع الوثائق', $reply);
        $this->assertStringNotContainsString('messages.', $reply);
        $nextStepMessage = (string) $response->json('data.result.next_step_message');
        $this->assertEquals('draft', $response->json('data.result.next_step_key'));
        $this->assertStringNotContainsString('messages.', $nextStepMessage);
    }

    public function test_next_step_for_payment_pending_application(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-PAY-PEND',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'payment_pending',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الخطوة القادمة؟',
        ])->assertOk();

        $this->assertEquals('get_application_next_step', $response->json('data.intent'));
        $this->assertStringContainsString('دفع', (string) $response->json('data.reply'));
        $this->assertFalse((bool) $response->json('data.requires_confirmation'));
    }

    public function test_next_step_with_no_applications(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو لازم أعمل هلق؟',
        ])->assertOk();

        $this->assertEquals('get_application_next_step', $response->json('data.intent'));
        $this->assertStringContainsString('لا يوجد', (string) $response->json('data.reply'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertNotEquals('general_help', $response->json('data.intent'));
    }

    public function test_next_step_with_multiple_applications_without_context(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        foreach (['APP-MULTI-A', 'APP-MULTI-B'] as $number) {
            LicenseApplication::query()->create([
                'application_number' => $number,
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'status' => 'draft',
            ]);
        }

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الخطوة القادمة؟',
        ])->assertOk();

        $this->assertEquals('get_application_next_step', $response->json('data.intent'));
        $this->assertStringContainsString('أكثر من طلب', (string) $response->json('data.reply'));
        $this->assertNull($response->json('data.pending_action'));
    }

    /**
     * @dataProvider applicationStatusNextStepProvider
     */
    public function test_all_application_statuses_have_arabic_next_step_message(string $status): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-STATUS-'.strtoupper($status),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => $status,
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'ما الخطوة التالية؟',
        ])->assertOk();

        $reply = (string) $response->json('data.reply');
        $this->assertNotEmpty($reply);
        $this->assertStringNotContainsString('messages.', $reply);
        $this->assertStringNotContainsString('application_next_step.', $reply);
        $this->assertStringNotContainsString('هي: '.$status, $reply);

        $nextStepMessage = (string) $response->json('data.result.next_step_message');
        $this->assertNotEmpty($nextStepMessage);
        $this->assertStringNotContainsString('messages.', $nextStepMessage);
        $this->assertStringNotContainsString('application_next_step.', $nextStepMessage);
    }

    public function test_unknown_status_next_step_fallback_does_not_leak_key(): void
    {
        $service = app(\App\Modules\AIAgent\Services\AgentApplicationNextStepService::class);
        $message = $service->nextStepMessageForStatus('nonexistent_status');

        $this->assertStringNotContainsString('messages.', $message);
        $this->assertStringNotContainsString('application_next_step.', $message);
        $this->assertStringContainsString('لم أتمكن', $message);
    }

    public function test_status_query_reply_includes_next_step_without_raw_keys(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-STATUS-NEXT',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();

        $reply = (string) $response->json('data.reply');
        $this->assertEquals('get_application_status', $response->json('data.intent'));
        $this->assertStringContainsString('مسودة', $reply);
        $this->assertStringContainsString('رفع الوثائق', $reply);
        $this->assertStringNotContainsString('messages.', $reply);

        $nextStepMessage = (string) $response->json('data.result.next_step_message');
        $this->assertStringNotContainsString('messages.', $nextStepMessage);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function applicationStatusNextStepProvider(): array
    {
        return [
            'draft' => ['draft'],
            'documents_under_review' => ['documents_under_review'],
            'documents_rejected' => ['documents_rejected'],
            'payment_pending' => ['payment_pending'],
            'payment_completed' => ['payment_completed'],
            'appointment_pending' => ['appointment_pending'],
            'in_testing' => ['in_testing'],
            'waiting_retest' => ['waiting_retest'],
            'approved' => ['approved'],
            'administrative_review' => ['administrative_review'],
            'license_issued' => ['license_issued'],
            'rejected' => ['rejected'],
            'cancelled' => ['cancelled'],
        ];
    }

    public function test_required_documents_intent_detection_with_single_draft_application(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-DOCS-1',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();

        $reply = (string) $response->json('data.reply');
        $this->assertEquals('get_required_documents', $response->json('data.intent'));
        $this->assertStringContainsString('الوثائق المطلوبة', $reply);
        $this->assertStringNotContainsString('messages.', $reply);
        $this->assertNotEquals('general_help', $response->json('data.intent'));
        $this->assertFalse((bool) $response->json('data.requires_confirmation'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertEquals('get_required_documents', $response->json('data.executed_action.name'));
        $this->assertNotEmpty($response->json('data.result.required_documents'));
        $this->assertStringNotContainsString('messages.', json_encode($response->json('data.result')));
    }

    public function test_required_documents_after_status_in_same_session(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-DOCS-STATUS',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $first = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();

        $sessionId = (int) $first->json('data.session_id');

        $second = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();

        $this->assertEquals('get_required_documents', $second->json('data.intent'));
        $this->assertStringContainsString('الوثائق المطلوبة', (string) $second->json('data.reply'));
        $this->assertEquals('APP-DOCS-STATUS', $second->json('data.result.application_number'));
    }

    public function test_required_documents_after_next_step_in_same_session(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-DOCS-NEXT',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $first = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الخطوة اللي بعدها؟',
        ])->assertOk();

        $sessionId = (int) $first->json('data.session_id');

        $second = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو لازم أرفع؟',
        ])->assertOk();

        $this->assertEquals('get_required_documents', $second->json('data.intent'));
        $this->assertStringContainsString('الوثائق المطلوبة', (string) $second->json('data.reply'));
        $this->assertNotEquals('create_new_license_application', $second->json('data.intent'));
        $this->assertNull($second->json('data.pending_action'));
    }

    public function test_required_documents_with_no_applications(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();

        $this->assertEquals('get_required_documents', $response->json('data.intent'));
        $this->assertStringContainsString('لا يوجد', (string) $response->json('data.reply'));
        $this->assertNotEquals('general_help', $response->json('data.intent'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertNull($response->json('data.executed_action'));
    }

    public function test_required_documents_with_multiple_applications_without_context(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        foreach (['APP-DOCS-A', 'APP-DOCS-B'] as $number) {
            LicenseApplication::query()->create([
                'application_number' => $number,
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'status' => 'draft',
            ]);
        }

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'ما هي الوثائق المطلوبة؟',
        ])->assertOk();

        $this->assertEquals('get_required_documents', $response->json('data.intent'));
        $this->assertStringContainsString('أكثر من طلب', (string) $response->json('data.reply'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertNotEquals('create_application', $response->json('data.pending_action.name'));
        $this->assertNull($response->json('data.executed_action'));
    }

    public function test_upload_phrase_does_not_trigger_create_application(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-UPLOAD-PHRASE',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو لازم أرفع؟',
        ])->assertOk();

        $this->assertEquals('get_required_documents', $response->json('data.intent'));
        $this->assertNotEquals('create_new_license_application', $response->json('data.intent'));
        $this->assertNotEquals('create_application', $response->json('data.pending_action.name'));
    }

    public function test_read_only_get_required_documents_stores_executed_action(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-DOCS-AUDIT',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();

        $this->assertDatabaseHas('ai_agent_actions', [
            'user_id' => $citizen->id,
            'action_name' => 'get_required_documents',
            'status' => 'executed',
        ]);
    }

    public function test_required_documents_after_upload_via_ai_agent(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-DOCS-UPLOADED',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);

        $checklist = $this->getJson("/api/applications/{$application->id}/required-documents")
            ->assertOk()
            ->json('data');

        $this->post(
            "/api/applications/{$application->id}/documents",
            [
                'required_document_id' => $checklist[0]['id'],
                'file' => \Illuminate\Http\UploadedFile::fake()->create(
                    'doc-'.$checklist[0]['code'].'.pdf',
                    80,
                    'application/pdf'
                ),
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();

        $this->assertEquals('get_required_documents', $response->json('data.intent'));
        $this->assertStringContainsString('الوثائق المطلوبة', (string) $response->json('data.reply'));
        $this->assertNotEmpty($response->json('data.result.required_documents'));

        $uploadedCount = collect($response->json('data.result.required_documents'))
            ->filter(fn (array $item): bool => ! empty($item['latest_document']))
            ->count();

        $this->assertSame(1, $uploadedCount);
        $this->assertStringNotContainsString('ApplicationDocumentResource', json_encode($response->json()));
    }

    public function test_read_only_get_application_status_executes_without_confirmation(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-READONLY',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'وين وصل الطلب؟',
        ])->assertOk();

        $this->assertFalse((bool) $response->json('data.requires_confirmation'));
        $this->assertEquals('get_application_status', $response->json('data.executed_action.name'));
        $this->assertDatabaseHas('ai_agent_actions', [
            'user_id' => $citizen->id,
            'action_name' => 'get_application_status',
            'status' => 'executed',
        ]);
    }
}
