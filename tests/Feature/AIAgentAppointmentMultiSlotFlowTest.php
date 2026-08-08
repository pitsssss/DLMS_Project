<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use App\Modules\AIAgent\Enums\PendingWorkflowState;
use App\Modules\AIAgent\Models\AIAgentSession;
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
use Tests\TestCase;

class AIAgentAppointmentMultiSlotFlowTest extends TestCase
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

    private function mockGemini(): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);
    }

    private function appointmentApp(User $citizen, string $number): LicenseApplication
    {
        return LicenseApplication::query()->create([
            'application_number' => $number,
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::AppointmentPending,
        ]);
    }

    private function visionSlot(): AppointmentSlot
    {
        $slot = AppointmentSlot::query()
            ->whereHas('testType', fn ($q) => $q->where('code', 'vision'))
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->first();

        $this->assertNotNull($slot);

        return $slot;
    }

    private function secondVisionSlot(int $excludeId): AppointmentSlot
    {
        $slot = AppointmentSlot::query()
            ->whereHas('testType', fn ($q) => $q->where('code', 'vision'))
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->whereKeyNot($excludeId)
            ->orderBy('date')
            ->orderBy('start_time')
            ->first();

        $this->assertNotNull($slot);

        return $slot;
    }

    public function test_book_end_to_end_single_application_button_and_confirm(): void
    {
        $citizen = $this->citizen();
        $this->appointmentApp($citizen, 'APP-APT-BOOK-1');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'احجزلي موعد',
        ])->assertOk();

        $this->assertSame('appointment_slot_selection_required', $ask->json('data.message_type'));
        $this->assertSame('book_appointment', $ask->json('data.intent'));
        $this->assertNotEmpty($ask->json('data.ui_payload.slots'));
        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.slots.0.selection_token');

        $selected = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_appointment_slot',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertSame('appointment_confirmation_required', $selected->json('data.message_type'));
        $this->assertTrue((bool) $selected->json('data.requires_confirmation'));
        $actionId = (int) $selected->json('data.pending_action.id');
        $this->assertSame('book_appointment', $selected->json('data.pending_action.name'));
        $this->assertNotNull($selected->json('data.pending_action.arguments.appointment_slot_id'));

        $confirm = $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();
        $this->assertSame('booked', $confirm->json('data.result.status'));

        $replay = $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertStatus(422);
        $this->assertStringContainsString('already', strtolower((string) $replay->json('message')));
        $this->assertSame(1, TestAppointment::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_book_multiple_applications_then_slot_text_ordinal(): void
    {
        $citizen = $this->citizen();
        $this->appointmentApp($citizen, 'APP-APT-M1');
        $this->appointmentApp($citizen, 'APP-APT-M2');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'احجز موعد',
        ])->assertOk();

        $this->assertSame('application_selection_required', $ask->json('data.message_type'));
        $sessionId = (int) $ask->json('data.session_id');
        $appToken = (string) $ask->json('data.ui_payload.applications.0.selection_token');

        $afterApp = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_application',
            'selection_token' => $appToken,
        ])->assertOk();

        $this->assertSame('appointment_slot_selection_required', $afterApp->json('data.message_type'));
        $this->assertNotEmpty($afterApp->json('data.ui_payload.slots'));

        $text = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'الأول',
        ])->assertOk();

        $this->assertSame('appointment_confirmation_required', $text->json('data.message_type'));
        $this->assertSame('book_appointment', $text->json('data.pending_action.name'));
    }

    public function test_slot_text_arabic_persian_digits_and_ambiguous_not_general_help(): void
    {
        $citizen = $this->citizen();
        $this->appointmentApp($citizen, 'APP-APT-DIG');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'احجزلي موعد'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->assertSame('appointment_slot_selection_required', $ask->json('data.message_type'));

        $digit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => '١',
        ])->assertOk();
        $this->assertSame('appointment_confirmation_required', $digit->json('data.message_type'));
        $this->assertNotSame('general_help', $digit->json('data.intent'));

        // Start fresh for ambiguous path.
        $citizen2 = $this->citizen();
        $this->appointmentApp($citizen2, 'APP-APT-AMB');
        Sanctum::actingAs($citizen2);
        $ask2 = $this->postJson('/api/ai-agent/message', ['message' => 'احجزلي موعد'])->assertOk();
        $session2 = (int) $ask2->json('data.session_id');

        $ambiguous = $this->postJson('/api/ai-agent/message', [
            'session_id' => $session2,
            'message' => 'موعد غير واضح',
        ])->assertOk();

        $this->assertSame('appointment_slot_selection_required', $ambiguous->json('data.message_type'));
        $this->assertNotSame('general_help', $ambiguous->json('data.intent'));
        $this->assertNotEmpty($ambiguous->json('data.ui_payload.slots'));
    }

    public function test_stale_slot_and_expired_and_foreign_token(): void
    {
        $citizen = $this->citizen();
        $this->appointmentApp($citizen, 'APP-APT-STALE');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'احجزلي موعد'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.slots.0.selection_token');
        $slotId = (int) (
            AIAgentSession::query()->findOrFail($sessionId)->context['pending_workflow']['candidate_slot_ids'][0] ?? 0
        );
        $this->assertGreaterThan(0, $slotId);

        AppointmentSlot::query()->whereKey($slotId)->update([
            'booked_count' => AppointmentSlot::query()->whereKey($slotId)->value('capacity'),
        ]);

        $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_appointment_slot',
            'selection_token' => $token,
        ])->assertStatus(422)->assertJsonPath('code', 'APPOINTMENT_SLOT_NO_LONGER_AVAILABLE');

        // Expired workflow
        $citizenB = $this->citizen();
        $this->appointmentApp($citizenB, 'APP-APT-EXP');
        Sanctum::actingAs($citizenB);
        $askB = $this->postJson('/api/ai-agent/message', ['message' => 'احجزلي موعد'])->assertOk();
        $sessionB = (int) $askB->json('data.session_id');
        $tokenB = (string) $askB->json('data.ui_payload.slots.0.selection_token');
        $sessionModel = AIAgentSession::query()->findOrFail($sessionB);
        $context = $sessionModel->context;
        $context['pending_workflow']['expires_at'] = now()->subMinute()->toIso8601String();
        $sessionModel->context = $context;
        $sessionModel->save();

        $this->postJson("/api/ai-agent/sessions/{$sessionB}/interactions", [
            'action' => 'select_appointment_slot',
            'selection_token' => $tokenB,
        ])->assertStatus(422)->assertJsonPath('code', 'PENDING_WORKFLOW_EXPIRED');

        // Foreign user token
        $owner = $this->citizen();
        $this->appointmentApp($owner, 'APP-APT-OWN');
        Sanctum::actingAs($owner);
        $askO = $this->postJson('/api/ai-agent/message', ['message' => 'احجزلي موعد'])->assertOk();
        $sessionO = (int) $askO->json('data.session_id');
        $tokenO = (string) $askO->json('data.ui_payload.slots.0.selection_token');

        $intruder = $this->citizen();
        Sanctum::actingAs($intruder);
        $this->postJson("/api/ai-agent/sessions/{$sessionO}/interactions", [
            'action' => 'select_appointment_slot',
            'selection_token' => $tokenO,
        ])->assertStatus(404);
    }

    public function test_no_slots_returns_structured_empty_response(): void
    {
        $citizen = $this->citizen();
        $this->appointmentApp($citizen, 'APP-APT-NOSLOT');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        AppointmentSlot::query()
            ->whereHas('testType', fn ($q) => $q->where('code', 'vision'))
            ->update(['is_active' => false]);

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'احجزلي موعد'])->assertOk();
        $this->assertSame('appointment_slot_selection_required', $ask->json('data.message_type'));
        $this->assertSame([], $ask->json('data.ui_payload.slots'));
        $this->assertNull($ask->json('data.pending_action'));
    }

    public function test_cancel_appointment_end_to_end(): void
    {
        $citizen = $this->citizen();
        $app = $this->appointmentApp($citizen, 'APP-APT-CANCEL');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $slot = $this->visionSlot();
        $appointment = TestAppointment::query()->create([
            'citizen_id' => $citizen->id,
            'application_id' => $app->id,
            'test_type_id' => $slot->test_type_id,
            'appointment_slot_id' => $slot->id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => now()->addDay(),
        ]);
        $slot->increment('booked_count');
        $app->update(['status' => ApplicationStatus::InTesting]);

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'الغي الموعد',
        ])->assertOk();

        $this->assertSame('appointment_confirmation_required', $ask->json('data.message_type'));
        $this->assertSame('cancel_appointment', $ask->json('data.pending_action.name'));
        $this->assertSame($appointment->id, (int) $ask->json('data.pending_action.arguments.appointment_id'));

        $actionId = (int) $ask->json('data.pending_action.id');
        $confirm = $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();
        $this->assertSame(AppointmentStatus::Cancelled->value, $confirm->json('data.result.status'));
    }

    public function test_reschedule_end_to_end_and_stale_replacement_slot(): void
    {
        $citizen = $this->citizen();
        $app = $this->appointmentApp($citizen, 'APP-APT-RES');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $slot = $this->visionSlot();
        $replacement = $this->secondVisionSlot((int) $slot->id);
        $appointment = TestAppointment::query()->create([
            'citizen_id' => $citizen->id,
            'application_id' => $app->id,
            'test_type_id' => $slot->test_type_id,
            'appointment_slot_id' => $slot->id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => now()->addDay(),
        ]);
        $slot->increment('booked_count');
        $app->update(['status' => ApplicationStatus::InTesting]);

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'تغيير الموعد',
        ])->assertOk();

        $this->assertSame('appointment_slot_selection_required', $ask->json('data.message_type'));
        $this->assertSame('reschedule_appointment', $ask->json('data.intent'));
        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.slots.0.selection_token');

        $selected = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_appointment_slot',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertSame('appointment_confirmation_required', $selected->json('data.message_type'));
        $this->assertSame('reschedule_appointment', $selected->json('data.pending_action.name'));
        $this->assertSame($appointment->id, (int) $selected->json('data.pending_action.arguments.appointment_id'));

        $newSlotId = (int) $selected->json('data.pending_action.arguments.appointment_slot_id');
        $actionId = (int) $selected->json('data.pending_action.id');

        $confirm = $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();
        $this->assertSame('booked', $confirm->json('data.result.status'));
        $this->assertSame($newSlotId, (int) $confirm->json('data.result.appointment_slot_id'));

        // Stale path on a fresh reschedule
        $ask2 = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'تغيير الموعد',
        ])->assertOk();
        $session2 = (int) $ask2->json('data.session_id');
        $token2 = (string) $ask2->json('data.ui_payload.slots.0.selection_token');
        $staleId = (int) (
            AIAgentSession::query()->findOrFail($session2)->context['pending_workflow']['candidate_slot_ids'][0] ?? 0
        );
        AppointmentSlot::query()->whereKey($staleId)->update([
            'booked_count' => AppointmentSlot::query()->whereKey($staleId)->value('capacity'),
        ]);

        $this->postJson("/api/ai-agent/sessions/{$session2}/interactions", [
            'action' => 'select_appointment_slot',
            'selection_token' => $token2,
        ])->assertStatus(422)->assertJsonPath('code', 'APPOINTMENT_SLOT_NO_LONGER_AVAILABLE');
    }

    public function test_multiple_appointments_require_selection_before_cancel(): void
    {
        $citizen = $this->citizen();
        $app = $this->appointmentApp($citizen, 'APP-APT-MULTI');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $slotA = $this->visionSlot();
        $slotB = $this->secondVisionSlot((int) $slotA->id);
        foreach ([$slotA, $slotB] as $slot) {
            TestAppointment::query()->create([
                'citizen_id' => $citizen->id,
                'application_id' => $app->id,
                'test_type_id' => $slot->test_type_id,
                'appointment_slot_id' => $slot->id,
                'status' => AppointmentStatus::Booked,
                'scheduled_at' => now()->addDays(2),
            ]);
            $slot->increment('booked_count');
        }
        $app->update(['status' => ApplicationStatus::InTesting]);

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'cancel appointment',
        ])->assertOk();

        $this->assertSame('appointment_selection_required', $ask->json('data.message_type'));
        $this->assertCount(2, $ask->json('data.ui_payload.appointments'));
        $this->assertNull($ask->json('data.pending_action'));

        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.appointments.0.selection_token');

        $selected = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_appointment',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertSame('appointment_confirmation_required', $selected->json('data.message_type'));
        $this->assertSame('cancel_appointment', $selected->json('data.pending_action.name'));
    }

    public function test_topic_change_and_cancel_phrase_clear_slot_workflow(): void
    {
        $citizen = $this->citizen();
        $this->appointmentApp($citizen, 'APP-APT-TOPIC');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'احجزلي موعد'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->assertSame(
            PendingWorkflowState::AwaitingAppointmentSlotChoice->value,
            AIAgentSession::query()->findOrFail($sessionId)->context['pending_workflow']['state'] ?? null
        );

        $topic = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();
        $this->assertSame('get_application_status', $topic->json('data.intent'));
        $this->assertArrayNotHasKey(
            'pending_workflow',
            AIAgentSession::query()->findOrFail($sessionId)->context ?? []
        );

        $ask2 = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'احجزلي موعد',
        ])->assertOk();
        $cancel = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'ما بدي',
        ])->assertOk();
        $this->assertSame('application_selection_cancelled', $cancel->json('data.message_type'));
    }

    public function test_english_book_flow_uses_english_replies(): void
    {
        $citizen = $this->citizen();
        $this->appointmentApp($citizen, 'APP-APT-EN');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'book appointment',
        ])->assertOk();

        $this->assertSame('appointment_slot_selection_required', $ask->json('data.message_type'));
        $reply = (string) $ask->json('data.reply');
        $this->assertStringContainsString('available slots', strtolower($reply));
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $reply);
    }

    public function test_current_appointments_returns_structured_data_without_forcing_selection(): void
    {
        $citizen = $this->citizen();
        $app = $this->appointmentApp($citizen, 'APP-APT-CUR');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $slotA = $this->visionSlot();
        $slotB = $this->secondVisionSlot((int) $slotA->id);
        foreach ([$slotA, $slotB] as $slot) {
            TestAppointment::query()->create([
                'citizen_id' => $citizen->id,
                'application_id' => $app->id,
                'test_type_id' => $slot->test_type_id,
                'appointment_slot_id' => $slot->id,
                'status' => AppointmentStatus::Booked,
                'scheduled_at' => now()->addDays(3),
            ]);
        }

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'حجزتلي موعد؟',
        ])->assertOk();

        $this->assertSame('get_current_appointments', $ask->json('data.intent'));
        $this->assertSame('get_current_appointments', $ask->json('data.executed_action.name'));
        $this->assertCount(2, $ask->json('data.result.appointments'));
        $this->assertNotSame('appointment_selection_required', $ask->json('data.message_type'));
    }

    public function test_tampered_slot_token_rejected(): void
    {
        $citizen = $this->citizen();
        $this->appointmentApp($citizen, 'APP-APT-TAMPER');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'احجزلي موعد'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.slots.0.selection_token');
        $tampered = substr($token, 0, -4).'xxxx';

        $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_appointment_slot',
            'selection_token' => $tampered,
        ])->assertStatus(422);
    }
}
