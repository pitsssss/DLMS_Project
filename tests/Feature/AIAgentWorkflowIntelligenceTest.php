<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
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

class AIAgentWorkflowIntelligenceTest extends TestCase
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

    private function mockGemini(?array $response): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn($response);
        $this->instance(GeminiAgentClient::class, $mock);
    }

    private function draftApplication(User $citizen, string $number = 'APP-WF-1'): LicenseApplication
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return LicenseApplication::query()->create([
            'application_number' => $number,
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
        ]);
    }

    private function appointmentPendingApplication(User $citizen, string $number = 'APP-WF-APT'): LicenseApplication
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return LicenseApplication::query()->create([
            'application_number' => $number,
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::AppointmentPending,
            'current_test_type_id' => null,
        ]);
    }

    public function test_fines_intent_executes_immediately(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini(['intent' => 'general_help', 'confidence' => 0.4, 'language' => 'ar', 'reply' => 'help']);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'الغرامات',
        ])->assertOk();

        $this->assertEquals('get_fines', $response->json('data.intent'));
        $this->assertFalse((bool) $response->json('data.requires_confirmation'));
        $this->assertEquals('get_fines', $response->json('data.executed_action.name'));
        $this->assertStringNotContainsString('messages.', (string) $response->json('data.reply'));
    }

    public function test_licenses_intent_executes_immediately(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'اعرضلي رخصي',
        ])->assertOk();

        $this->assertEquals('get_licenses', $response->json('data.intent'));
        $this->assertEquals('get_licenses', $response->json('data.executed_action.name'));
    }

    public function test_payment_blocked_when_application_is_draft(): void
    {
        $citizen = $this->citizen();
        $this->draftApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي ادفع',
        ])->assertOk();

        $this->assertEquals('start_payment', $response->json('data.intent'));
        $this->assertStringContainsString('لا يمكنك الدفع', (string) $response->json('data.reply'));
        $this->assertNull($response->json('data.executed_action'));
    }

    public function test_appointment_blocked_when_application_is_draft(): void
    {
        $citizen = $this->citizen();
        $this->draftApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'احجزلي موعد',
        ])->assertOk();

        $this->assertEquals('get_appointment_slots', $response->json('data.intent'));
        $this->assertStringContainsString('لا يمكنك', (string) $response->json('data.reply'));
        $this->assertNull($response->json('data.executed_action'));
    }

    public function test_admin_action_denied_for_document_approval_phrase(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'وافقلي على وثائقي',
        ])->assertOk();

        $this->assertEquals('admin_action_denied', $response->json('data.intent'));
        $this->assertStringContainsString('موظف', (string) $response->json('data.reply'));
    }

    public function test_deterministic_intent_overrides_gemini_general_help(): void
    {
        $citizen = $this->citizen();
        $this->draftApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini([
            'intent' => 'general_help',
            'confidence' => 0.99,
            'language' => 'ar',
            'reply' => 'أنا مساعد خدمات رخص القيادة',
            'missing_slots' => [],
            'proposed_action' => null,
            'requires_confirmation' => false,
        ]);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();

        $this->assertEquals('get_required_documents', $response->json('data.intent'));
        $this->assertNotEquals('general_help', $response->json('data.intent'));
    }

    public function test_workflow_context_continues_after_status(): void
    {
        $citizen = $this->citizen();
        $this->draftApplication($citizen, 'APP-WF-CTX');
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $first = $this->postJson('/api/ai-agent/message', ['message' => 'شو حالة طلبي؟'])->assertOk();
        $sessionId = (int) $first->json('data.session_id');

        $second = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو لازم أرفع؟',
        ])->assertOk();

        $this->assertEquals('get_required_documents', $second->json('data.intent'));
    }

    public function test_possessive_phrase_does_not_map_to_private_license(): void
    {
        $citizen = $this->citizen();
        $this->draftApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'الطلب الخاص بي',
        ])->assertOk();

        $this->assertEquals('get_application_status', $response->json('data.intent'));
        $this->assertNotEquals('create_new_license_application', $response->json('data.intent'));
    }

    public function test_available_tests_intent_executes_immediately(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الفحوص المتاحة لي؟',
        ])->assertOk();

        $this->assertSame('تم إنشاء رد المساعد الذكي بنجاح.', $response->json('message'));
        $this->assertEquals('get_available_tests', $response->json('data.intent'));
        $this->assertFalse((bool) $response->json('data.requires_confirmation'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertEquals('get_available_tests', $response->json('data.executed_action.name'));
        $this->assertEquals('executed', $response->json('data.executed_action.status'));
        $this->assertNotEmpty($response->json('data.result.tests'));
        $this->assertTrue((bool) collect($response->json('data.result.tests'))->firstWhere('code', 'vision')['is_available']);
        $this->assertStringContainsString('اختبار النظر', (string) $response->json('data.reply'));
        $this->assertStringNotContainsString('messages.', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_appointment_slots_intent_executes_immediately_with_vision_test_type(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'اعرض المواعيد المتاحة لفحص النظر',
        ])->assertOk();

        $this->assertSame('تم إنشاء رد المساعد الذكي بنجاح.', $response->json('message'));
        $this->assertEquals('get_appointment_slots', $response->json('data.intent'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertEquals('get_appointment_slots', $response->json('data.executed_action.name'));
        $this->assertEquals('executed', $response->json('data.executed_action.status'));
        $this->assertEquals('vision', $response->json('data.result.test_type.code'));
        $this->assertIsArray($response->json('data.result.slots'));
        $this->assertNotEmpty($response->json('data.result.slots'));
        $this->assertStringContainsString('المواعيد المتاحة', (string) $response->json('data.reply'));
    }

    public function test_vague_book_appointment_shows_slots_without_executing_booking(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'احجزلي موعد',
        ])->assertOk();

        $this->assertEquals('get_appointment_slots', $response->json('data.intent'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertEquals('get_appointment_slots', $response->json('data.executed_action.name'));
        $this->assertNotEquals('book_appointment', $response->json('data.executed_action.name'));
    }

    public function test_book_first_available_slot_creates_pending_confirmation_action(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'احجز أول موعد متاح',
        ])->assertOk();

        $this->assertEquals('book_appointment', $response->json('data.intent'));
        $this->assertTrue((bool) $response->json('data.requires_confirmation'));
        $this->assertEquals('book_appointment', $response->json('data.pending_action.name'));
        $this->assertNull($response->json('data.executed_action'));
        $this->assertNotNull($response->json('data.pending_action.arguments.appointment_slot_id'));
    }

    public function test_current_appointments_after_booking_confirmation(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $bookResponse = $this->postJson('/api/ai-agent/message', [
            'message' => 'احجز أول موعد متاح',
        ])->assertOk();

        $actionId = $bookResponse->json('data.pending_action.id');
        $confirmResponse = $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();
        $sessionId = $bookResponse->json('data.session_id');

        $this->assertEquals('booked', $confirmResponse->json('data.result.status'));
        $this->assertNotNull($confirmResponse->json('data.result.date'));
        $this->assertNotNull($confirmResponse->json('data.result.start_time'));

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'حجزتلي موعد؟',
            'session_id' => $sessionId,
        ])->assertOk();

        $this->assertEquals('get_current_appointments', $response->json('data.intent'));
        $this->assertFalse((bool) $response->json('data.requires_confirmation'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertEquals('get_current_appointments', $response->json('data.executed_action.name'));
        $this->assertEquals('executed', $response->json('data.executed_action.status'));
        $this->assertStringContainsString('تم حجز موعد', (string) $response->json('data.reply'));
        $this->assertStringContainsString('اختبار النظر', (string) $response->json('data.reply'));
        $this->assertNotEmpty($response->json('data.result.appointments'));
        $this->assertNotEquals('general_help', $response->json('data.intent'));
        $this->assertStringNotContainsString('messages.', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @dataProvider currentAppointmentsPhraseProvider
     */
    public function test_current_appointments_phrase_detection(string $phrase): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => $phrase,
        ])->assertOk();

        $this->assertEquals('get_current_appointments', $response->json('data.intent'));
        $this->assertNotEquals('general_help', $response->json('data.intent'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function currentAppointmentsPhraseProvider(): array
    {
        return [
            'do_i_have_appointment' => ['عندي موعد؟'],
            'what_is_my_appointment' => ['شو موعدي؟'],
            'when_is_my_appointment' => ['متى موعدي؟'],
            'show_my_appointment' => ['اعرضلي موعدي'],
        ];
    }

    public function test_current_appointments_none_suggests_booking_actions(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'عندي موعد؟',
        ])->assertOk();

        $this->assertEquals('get_current_appointments', $response->json('data.intent'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertStringContainsString('لا يوجد لديك موعد محجوز', (string) $response->json('data.reply'));
        $this->assertContains('get_appointment_slots', $response->json('data.suggested_next_actions'));
    }

    public function test_current_appointments_no_application_reply(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو موعدي؟',
        ])->assertOk();

        $this->assertEquals('get_current_appointments', $response->json('data.intent'));
        $this->assertStringContainsString('لا يوجد لديك طلب حالي', (string) $response->json('data.reply'));
        $this->assertNotEquals('general_help', $response->json('data.intent'));
        $this->assertNull($response->json('data.executed_action'));
    }

    public function test_follow_up_when_is_my_appointment_uses_booked_context(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $bookResponse = $this->postJson('/api/ai-agent/message', [
            'message' => 'احجز أول موعد متاح',
        ])->assertOk();

        $actionId = $bookResponse->json('data.pending_action.id');
        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();

        $sessionId = $bookResponse->json('data.session_id');

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'متى موعدي؟',
            'session_id' => $sessionId,
        ])->assertOk();

        $this->assertEquals('get_current_appointments', $response->json('data.intent'));
        $this->assertEquals('get_current_appointments', $response->json('data.executed_action.name'));
        $this->assertNotEmpty($response->json('data.result.appointments'));
        $this->assertStringContainsString('تم حجز موعد', (string) $response->json('data.reply'));
    }

    public function test_record_test_result_request_is_admin_denied(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'ثبتلي النتيجة',
        ])->assertOk();

        $this->assertEquals('admin_action_denied', $response->json('data.intent'));
        $this->assertNotEquals('general_help', $response->json('data.intent'));
    }

    public function test_available_tests_blocked_before_appointment_stage(): void
    {
        $citizen = $this->citizen();
        $this->draftApplication($citizen);
        Sanctum::actingAs($citizen);
        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الفحوص المتاحة لي؟',
        ])->assertOk();

        $this->assertEquals('get_available_tests', $response->json('data.intent'));
        $this->assertStringContainsString('لا يمكنك', (string) $response->json('data.reply'));
        $this->assertNull($response->json('data.executed_action'));
        $this->assertNotEquals('general_help', $response->json('data.intent'));
    }
}
