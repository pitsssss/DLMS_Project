<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use App\Modules\AIAgent\Support\AgentCatalogLocalizer;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\FeesSeeder;
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

class AIAgentCatalogLocalizationTest extends TestCase
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
            FeesSeeder::class,
            RequiredDocumentsSeeder::class,
            AppointmentSlotsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockGemini(): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);
    }

    private function citizenWithDraft(): User
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        LicenseApplication::query()->create([
            'application_number' => 'APP-CAT-DOC',
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::Draft,
        ]);

        return $citizen;
    }

    public function test_catalog_localizer_resolves_known_codes(): void
    {
        $this->assertSame('Copy of national ID', AgentCatalogLocalizer::document('national_id_copy', null, 'en'));
        $this->assertSame('صورة عن الهوية الشخصية', AgentCatalogLocalizer::document('national_id_copy', null, 'ar'));
        $this->assertSame('Vision test', AgentCatalogLocalizer::testType('vision', null, 'en'));
        $this->assertSame('اختبار النظر', AgentCatalogLocalizer::testType('vision', null, 'ar'));
        $this->assertSame('New license', AgentCatalogLocalizer::serviceType('new_license', null, 'en'));
        $this->assertSame('Private', AgentCatalogLocalizer::licenseType('private', null, 'en'));
    }

    public function test_english_required_documents_reply_has_no_arabic_catalog_labels(): void
    {
        Sanctum::actingAs($this->citizenWithDraft());
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'What documents do I need?',
        ])->assertOk();

        $this->assertSame('en', $response->json('data.language'));
        $this->assertSame('ltr', $response->json('data.text_direction'));
        $reply = (string) $response->json('data.reply');
        $this->assertStringContainsString('Copy of national ID', $reply);
        $this->assertStringContainsString('Personal photo', $reply);
        $this->assertStringContainsString('Medical report', $reply);
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $reply);

        $ui = $response->json('data.ui_payload');
        if (is_array($ui) && isset($ui['documents'])) {
            foreach ($ui['documents'] as $doc) {
                $label = (string) ($doc['label'] ?? '');
                $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $label);
            }
        }
    }

    public function test_arabic_required_documents_reply_uses_arabic_catalog_labels(): void
    {
        Sanctum::actingAs($this->citizenWithDraft());
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();

        $this->assertSame('ar', $response->json('data.language'));
        $this->assertSame('rtl', $response->json('data.text_direction'));
        $reply = (string) $response->json('data.reply');
        $this->assertStringContainsString('صورة عن الهوية الشخصية', $reply);
        $this->assertStringNotContainsString('Copy of national ID', $reply);
    }

    public function test_language_switch_changes_subsequent_document_labels(): void
    {
        Sanctum::actingAs($this->citizenWithDraft());
        $this->mockGemini();

        $ar = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();
        $sessionId = (int) $ar->json('data.session_id');
        $this->assertSame('ar', $ar->json('data.language'));

        $en = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'What documents do I need?',
        ])->assertOk();

        $this->assertSame('en', $en->json('data.language'));
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', (string) $en->json('data.reply'));
        $this->assertStringContainsString('Medical report', (string) $en->json('data.reply'));
    }

    public function test_contract_fields_on_document_offer(): void
    {
        Sanctum::actingAs($this->citizenWithDraft());
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'What documents do I need?',
        ])->assertOk();

        $data = $response->json('data');
        $this->assertArrayHasKey('session_id', $data);
        $this->assertArrayHasKey('language', $data);
        $this->assertArrayHasKey('locale', $data);
        $this->assertArrayHasKey('text_direction', $data);
        $this->assertArrayHasKey('message_type', $data);
        $this->assertArrayHasKey('requires_confirmation', $data);
        $this->assertSame('en', $data['language']);
        $this->assertSame('ltr', $data['text_direction']);
    }
}
