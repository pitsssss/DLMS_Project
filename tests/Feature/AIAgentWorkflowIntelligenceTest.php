<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
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

        $this->assertEquals('book_appointment', $response->json('data.intent'));
        $this->assertStringContainsString('لا يمكنك حجز موعد', (string) $response->json('data.reply'));
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
}
