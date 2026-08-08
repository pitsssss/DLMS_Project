<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentEvaluation;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AIAgentActionExecutionTest extends TestCase
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

    private function awaitingAction(
        User $citizen,
        string $actionName = 'create_application',
        array $arguments = [
            'license_type_code' => 'private',
            'service_type_code' => 'new_license',
        ],
    ): AIAgentAction {
        $session = AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => 'active',
            'current_intent' => 'create_new_license_application',
            'context' => [],
        ]);

        return AIAgentAction::query()->create([
            'session_id' => $session->id,
            'user_id' => $citizen->id,
            'action_name' => $actionName,
            'arguments' => $arguments,
            'status' => AgentActionStatus::AwaitingConfirmation,
            'requires_confirmation' => true,
            'confirmation_message' => 'هل تؤكد المتابعة؟',
        ]);
    }

    public function test_citizen_can_confirm_create_application_action(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.action.name', 'create_application')
            ->assertJsonPath('data.action.status', 'executed')
            ->assertJsonPath('data.result.status', ApplicationStatus::Draft->value)
            ->assertJsonStructure([
                'data' => [
                    'action' => ['id', 'name', 'status'],
                    'result' => ['application_id', 'application_number', 'status'],
                    'reply',
                ],
            ]);
    }

    public function test_confirming_create_application_creates_license_application(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $this->assertEquals(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());

        $response = $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $applicationId = (int) $response->json('data.result.application_id');

        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
        $this->assertDatabaseHas('license_applications', [
            'id' => $applicationId,
            'citizen_id' => $citizen->id,
            'status' => ApplicationStatus::Draft->value,
        ]);
    }

    public function test_action_status_and_result_are_stored_after_success(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $action->refresh();

        $this->assertSame(AgentActionStatus::Executed, $action->status);
        $this->assertNotNull($action->executed_at);
        $this->assertNotNull($action->confirmed_at);
        $this->assertIsArray($action->result);
        $this->assertArrayHasKey('application_id', $action->result);
    }

    public function test_citizen_cannot_confirm_another_citizen_action(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();
        $action = $this->awaitingAction($owner);

        Sanctum::actingAs($other);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertNotFound();
    }

    public function test_guest_cannot_confirm_action(): void
    {
        $citizen = $this->citizen();
        $action = $this->awaitingAction($citizen);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertUnauthorized();
    }

    public function test_employee_cannot_use_citizen_ai_action_endpoint(): void
    {
        $citizen = $this->citizen();
        $action = $this->awaitingAction($citizen);

        $employee = User::factory()->dashboardEmployee('employee')->create();

        Sanctum::actingAs($employee);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertForbidden();
    }

    public function test_cancelled_action_cannot_be_confirmed(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);
        $action->status = AgentActionStatus::Cancelled;
        $action->save();

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_executed_action_cannot_be_confirmed_again(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This action has already been executed.');
    }

    public function test_forbidden_admin_action_cannot_be_executed(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen, 'approve_document', [
            'application_id' => 1,
        ]);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertForbidden();

        $action->refresh();
        $this->assertSame(AgentActionStatus::Failed, $action->status);
        $this->assertNotNull($action->error_message);
    }

    public function test_create_application_respects_profile_completion_rule(): void
    {
        $citizen = User::factory()->create([
            'profile_completed' => false,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $message = __('messages.profile.must_complete');

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertStatus(403)
            ->assertJsonPath('message', $message);

        $action->refresh();
        $this->assertSame(AgentActionStatus::Failed, $action->status);
        $this->assertSame($message, $action->error_message);
        $this->assertEquals(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_failed_action_stores_error_message(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen, 'create_application', [
            'license_type_code' => 'invalid_type',
            'service_type_code' => 'new_license',
        ]);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertStatus(422);

        $action->refresh();
        $this->assertSame(AgentActionStatus::Failed, $action->status);
        $this->assertNotNull($action->error_message);
    }

    public function test_cancel_endpoint_changes_status_to_cancelled(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $this->postJson("/api/ai-agent/actions/{$action->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.action.status', 'cancelled')
            ->assertJsonPath('data.reply', 'تم إلغاء العملية. يمكنك طلب المساعدة من جديد في أي وقت.');

        $this->assertDatabaseHas('ai_agent_actions', [
            'id' => $action->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_assistant_message_is_saved_after_action_execution(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $this->assertDatabaseHas('ai_agent_messages', [
            'session_id' => $action->session_id,
            'role' => 'assistant',
        ]);

        $message = AIAgentMessage::query()
            ->where('session_id', $action->session_id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('تم إنشاء طلب', $message->content);
        $this->assertEquals('executed', $message->metadata['outcome'] ?? null);
    }

    public function test_evaluation_row_is_created_on_execution(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $this->assertDatabaseHas('ai_agent_evaluations', [
            'session_id' => $action->session_id,
            'tool_selected' => 'create_application',
            'model_used' => 'action_executor',
        ]);
    }

    public function test_full_flow_message_session_confirm_creates_application(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')
            ->twice()
            ->andReturn(
                [
                    'intent' => 'create_new_license_application',
                    'confidence' => 0.91,
                    'language' => 'ar',
                    'reply' => 'ما نوع الرخصة؟',
                    'missing_slots' => ['license_type'],
                    'proposed_action' => null,
                    'requires_confirmation' => false,
                    'safety_status' => 'safe',
                    'requires_human_support' => false,
                ],
                [
                    'intent' => 'general_help',
                    'confidence' => 0.45,
                    'language' => 'ar',
                    'reply' => 'كيف يمكنني مساعدتك؟',
                    'missing_slots' => [],
                    'proposed_action' => null,
                    'requires_confirmation' => false,
                    'safety_status' => 'safe',
                    'requires_human_support' => false,
                ]
            );
        $this->instance(GeminiAgentClient::class, $mock);

        $first = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();

        $sessionId = (int) $first->json('data.session_id');

        $second = $this->postJson('/api/ai-agent/message', [
            'message' => 'رخصة خاصة',
            'session_id' => $sessionId,
        ])->assertOk();

        $actionId = (int) $second->json('data.pending_action.id');

        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.action.status', 'executed');

        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_get_fines_action_can_be_confirmed(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen, 'get_fines', []);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.action.status', 'executed')
            ->assertJsonStructure(['data' => ['result' => ['items']]]);
    }

    public function test_confirm_create_application_fails_when_duplicate_active_application_exists(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-DUP-TEST-1',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
        ]);

        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'يوجد لديك طلب فعال مسبقاً لنفس نوع الرخصة والخدمة. يمكنك متابعة الطلب الحالي بدلاً من إنشاء طلب جديد.'
            );

        $action->refresh();
        $this->assertSame(AgentActionStatus::Failed, $action->status);
        $this->assertSame(
            'يوجد لديك طلب فعال مسبقاً لنفس نوع الرخصة والخدمة. يمكنك متابعة الطلب الحالي بدلاً من إنشاء طلب جديد.',
            $action->error_message
        );
        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_citizen_b_not_blocked_by_citizen_a_active_application_on_confirm(): void
    {
        $citizenA = $this->citizen();
        $citizenB = $this->citizen();

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-A-ONLY',
            'citizen_id' => $citizenA->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
        ]);

        Sanctum::actingAs($citizenB);
        $action = $this->awaitingAction($citizenB);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizenB->id)->count());
        $this->assertEquals(1, LicenseApplication::query()->where('citizen_id', $citizenA->id)->count());
    }

    public function test_get_application_next_step_returns_arabic_message_not_translation_key(): void
    {
        app()->setLocale('en');

        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-NEXT-EXEC',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
        ]);

        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen, 'get_application_next_step', [
            'application_id' => $application->id,
        ]);
        $action->requires_confirmation = false;
        $action->save();

        $response = $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $this->assertStringContainsString('رفع الوثائق', (string) $response->json('data.reply'));
        $this->assertStringNotContainsString('messages.', (string) $response->json('data.reply'));
        $this->assertEquals('draft', $response->json('data.result.next_step_key'));
        $this->assertStringNotContainsString('messages.', (string) $response->json('data.result.next_step_message'));
    }

    public function test_get_application_status_requires_owned_application(): void
    {
        $citizen = $this->citizen();
        $other = $this->citizen();

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-TEST-OTHER-1',
            'citizen_id' => $other->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
        ]);

        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen, 'get_application_status', [
            'application_id' => $application->id,
        ]);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertNotFound();

        $action->refresh();
        $this->assertSame(AgentActionStatus::Failed, $action->status);
    }

    public function test_get_current_appointments_executes_without_confirmation(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-CURRENT-APT',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::AppointmentPending,
        ]);

        Sanctum::actingAs($citizen);

        $action = $this->awaitingAction($citizen, 'get_current_appointments', [
            'application_id' => $application->id,
        ]);
        $action->requires_confirmation = false;
        $action->save();

        $response = $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $this->assertEquals('get_current_appointments', $response->json('data.action.name'));
        $this->assertEquals('executed', $response->json('data.action.status'));
        $this->assertSame([], $response->json('data.result.appointments'));
        $this->assertStringContainsString('لا يوجد لديك موعد محجوز', (string) $response->json('data.reply'));
        $this->assertStringNotContainsString('messages.', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_book_appointment_confirm_returns_structured_appointment_payload(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-BOOK-EXEC',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::AppointmentPending,
        ]);

        Sanctum::actingAs($citizen);

        $slotId = \App\Models\AppointmentSlot::query()->whereHas('testType', fn ($q) => $q->where('code', 'vision'))->value('id');
        $this->assertNotNull($slotId);

        $action = $this->awaitingAction($citizen, 'book_appointment', [
            'application_id' => LicenseApplication::query()->where('application_number', 'APP-BOOK-EXEC')->value('id'),
            'appointment_slot_id' => $slotId,
            'test_type_code' => 'vision',
        ]);

        $response = $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $this->assertEquals('booked', $response->json('data.result.status'));
        $this->assertNotNull($response->json('data.result.appointment_id'));
        $this->assertNotNull($response->json('data.result.date'));
        $this->assertNotNull($response->json('data.result.start_time'));
        $this->assertEquals('vision', $response->json('data.result.test_type.code'));
        $this->assertStringContainsString('تم حجز موعد', (string) $response->json('data.reply'));
    }

    public function test_book_appointment_confirm_theory_before_vision_returns_arabic_message(): void
    {
        app()->setLocale('en');

        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-BOOK-THEORY',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::AppointmentPending,
        ]);

        Sanctum::actingAs($citizen);

        $theory = \App\Models\TestType::query()->where('code', 'theory')->firstOrFail();
        $slotId = \App\Models\AppointmentSlot::query()
            ->where('test_type_id', $theory->id)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->value('id');

        $this->assertNotNull($slotId);

        $action = $this->awaitingAction($citizen, 'book_appointment', [
            'application_id' => $application->id,
            'appointment_slot_id' => $slotId,
            'test_type_code' => 'theory',
        ]);

        $response = $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertStatus(422);

        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('messages.', $message);
        $this->assertStringNotContainsString('messages.', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('لا يمكن حجز هذا الاختبار حالياً', $message);
        $this->assertStringContainsString('فحص النظر', $message);
        $this->assertStringContainsString('الفحص النظري', $message);

        $action->refresh();
        $this->assertSame(AgentActionStatus::Failed, $action->status);
        $this->assertStringNotContainsString('messages.', (string) $action->error_message);
        $this->assertEquals(0, \App\Models\TestAppointment::query()->where('application_id', $application->id)->count());
    }
}
