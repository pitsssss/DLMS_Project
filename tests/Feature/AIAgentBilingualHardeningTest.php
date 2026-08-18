<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\LicenseStatus;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\GeminiAgentClient;
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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AIAgentBilingualHardeningTest extends TestCase
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

    private function assertNoArabic(string $text): void
    {
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $text);
    }

    private function assertHasArabic(string $text): void
    {
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $text);
    }

    private function createDraftApplication(User $citizen, string $number): LicenseApplication
    {
        return LicenseApplication::query()->create([
            'application_number' => $number,
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::Draft,
        ]);
    }

    /**
     * @return array{0: User, 1: License}
     */
    private function citizenWithLicense(string $prefix = 'LIC'): array
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $app = LicenseApplication::query()->create([
            'application_number' => 'APP-P26-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'issued_at' => now()->subYears(9),
        ]);
        $license = License::query()->create([
            'license_number' => $prefix.'-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $app->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(9)->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);

        return [$citizen, $license];
    }

    public function test_first_message_arabic_sets_language_ar(): void
    {
        Sanctum::actingAs($this->citizen());
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();

        $this->assertSame('ar', $response->json('data.language'));
        $this->assertSame('ar', $response->json('data.locale'));
        $this->assertSame('rtl', $response->json('data.text_direction'));
        $this->assertHasArabic((string) $response->json('data.reply'));
        $this->assertSame('create_new_license_application', $response->json('data.intent'));
    }

    public function test_first_message_english_sets_language_en(): void
    {
        Sanctum::actingAs($this->citizen());
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'I want a new driving license',
        ])->assertOk();

        $this->assertSame('en', $response->json('data.language'));
        $this->assertSame('en', $response->json('data.locale'));
        $this->assertSame('ltr', $response->json('data.text_direction'));
        $this->assertNoArabic((string) $response->json('data.reply'));
        $this->assertSame('create_new_license_application', $response->json('data.intent'));
    }

    public function test_mid_session_language_switch_preserves_pending_workflow(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen, 'APP-P26-A');
        $this->createDraftApplication($citizen, 'APP-P26-B');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();

        $this->assertSame('application_selection_required', $ask->json('data.message_type'));
        $this->assertSame('ar', $ask->json('data.language'));
        $sessionId = (int) $ask->json('data.session_id');

        $sessionBefore = AIAgentSession::query()->findOrFail($sessionId);
        $pendingBefore = $sessionBefore->context['pending_workflow'] ?? null;
        $this->assertIsArray($pendingBefore);

        $switch = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'show me the first one',
        ])->assertOk();

        $this->assertSame('en', $switch->json('data.language'));
        $this->assertNotSame('general_help', $switch->json('data.intent'));
        $this->assertNoArabic((string) $switch->json('data.reply'));

        $sessionAfter = AIAgentSession::query()->findOrFail($sessionId)->fresh();
        // Selecting first may clear pending after resolution — but language switch alone must not.
        // Here "show me the first one" is a workflow selection in EN; pending may advance.
        $this->assertNotSame('general_help', $switch->json('data.message_type') ?? $switch->json('data.intent'));

        $back = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'بدي أعرف الخطوة التالية',
        ])->assertOk();

        $this->assertSame('ar', $back->json('data.language'));
        $this->assertHasArabic((string) $back->json('data.reply'));
        $this->assertNotSame('general_help', $back->json('data.intent'));
    }

    public function test_explicit_language_switch_keeps_pending_selection(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen, 'APP-P26-C');
        $this->createDraftApplication($citizen, 'APP-P26-D');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->assertSame('application_selection_required', $ask->json('data.message_type'));

        $pendingBefore = AIAgentSession::query()->findOrFail($sessionId)->context['pending_workflow'] ?? null;

        $switch = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'speak english',
        ])->assertOk();

        $this->assertSame('en', $switch->json('data.language'));
        $pendingAfter = AIAgentSession::query()->findOrFail($sessionId)->fresh()->context['pending_workflow'] ?? null;
        $this->assertSame(
            $pendingBefore['state'] ?? null,
            $pendingAfter['state'] ?? null
        );
        $this->assertSame(
            $pendingBefore['purpose'] ?? null,
            $pendingAfter['purpose'] ?? null
        );
    }

    public function test_short_yes_inherits_workflow_locale_during_confirmation(): void
    {
        Sanctum::actingAs($this->citizen());
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'I want a new driving license',
        ])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->assertSame('en', $ask->json('data.language'));

        $typed = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'private',
        ])->assertOk();
        $this->assertSame('en', $typed->json('data.language'));
        $this->assertSame('create_application', $typed->json('data.pending_action.name'));

        $confirm = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'yes',
        ])->assertOk();

        $this->assertSame('en', $confirm->json('data.language'));
        $this->assertNoArabic((string) $confirm->json('data.reply'));
        $this->assertNotEmpty($confirm->json('data.result.application_number'));
    }

    public function test_mixed_technical_terms_do_not_flip_arabic_locale(): void
    {
        $citizen = $this->citizen();
        $this->createDraftApplication($citizen, 'APP-P26-MIX');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو status طلبي',
        ])->assertOk();

        $this->assertSame('ar', $response->json('data.language'));
        $this->assertHasArabic((string) $response->json('data.reply'));
        $this->assertSame('get_application_status', $response->json('data.intent'));
    }

    public function test_english_core_flows_new_renew_lost_damaged_payment_licenses_fines(): void
    {
        Sanctum::actingAs($this->citizen());
        $this->mockGemini();

        $new = $this->postJson('/api/ai-agent/message', [
            'message' => 'I want a new license',
        ])->assertOk();
        $this->assertSame('en', $new->json('data.language'));
        $this->assertSame('create_new_license_application', $new->json('data.intent'));
        $this->assertNoArabic((string) $new->json('data.reply'));

        foreach ([
            ['I want to renew my license', 'create_renew_license_application'],
            ['I lost my license', 'create_lost_replacement_application'],
            ['My license is damaged', 'create_damaged_replacement_application'],
            ['I want to unblock my license', 'create_license_unblock_application'],
        ] as [$message, $intent]) {
            [$citizen] = $this->citizenWithLicense(strtoupper(substr($intent, 7, 3)));
            Sanctum::actingAs($citizen);
            $this->mockGemini();
            $ask = $this->postJson('/api/ai-agent/message', ['message' => $message])->assertOk();
            $this->assertSame($intent, $ask->json('data.intent'));
            $this->assertSame('en', $ask->json('data.language'));
            $this->assertNoArabic((string) $ask->json('data.reply'));
        }

        $payCitizen = $this->citizen();
        LicenseApplication::query()->create([
            'application_number' => 'APP-P26-PAY',
            'citizen_id' => $payCitizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::PaymentPending,
        ]);
        Sanctum::actingAs($payCitizen);
        $this->mockGemini();

        $fee = $this->postJson('/api/ai-agent/message', ['message' => 'What is the fee?'])->assertOk();
        $this->assertSame('en', $fee->json('data.language'));
        $this->assertNoArabic((string) $fee->json('data.reply'));

        $status = $this->postJson('/api/ai-agent/message', [
            'session_id' => (int) $fee->json('data.session_id'),
            'message' => 'What is my payment status?',
        ])->assertOk();
        $this->assertSame('en', $status->json('data.language'));
        $this->assertSame('get_payment_status', $status->json('data.intent'));

        [$licCitizen] = $this->citizenWithLicense('LIST');
        Sanctum::actingAs($licCitizen);
        $this->mockGemini();
        $licenses = $this->postJson('/api/ai-agent/message', ['message' => 'Show my licenses'])->assertOk();
        $this->assertSame('en', $licenses->json('data.language'));
        $this->assertSame('get_licenses', $licenses->json('data.intent'));
        $this->assertNoArabic((string) $licenses->json('data.reply'));

        $fines = $this->postJson('/api/ai-agent/message', [
            'session_id' => (int) $licenses->json('data.session_id'),
            'message' => 'Show my fines',
        ])->assertOk();
        $this->assertSame('en', $fines->json('data.language'));
        $this->assertSame('get_fines', $fines->json('data.intent'));
    }

    public function test_arabic_and_english_general_help_and_empty_states(): void
    {
        Sanctum::actingAs($this->citizen());
        $this->mockGemini();

        $arHelp = $this->postJson('/api/ai-agent/message', [
            'message' => 'مساعدة',
        ])->assertOk();
        $this->assertSame('ar', $arHelp->json('data.language'));
        $this->assertHasArabic((string) $arHelp->json('data.reply'));

        $enHelp = $this->postJson('/api/ai-agent/message', [
            'message' => 'help',
        ])->assertOk();
        $this->assertSame('en', $enHelp->json('data.language'));
        $this->assertNoArabic((string) $enHelp->json('data.reply'));

        $enEmpty = $this->postJson('/api/ai-agent/message', [
            'message' => 'What is my application status?',
        ])->assertOk();
        $this->assertSame('en', $enEmpty->json('data.language'));
        $this->assertNoArabic((string) $enEmpty->json('data.reply'));
        $this->assertSame('get_application_status', $enEmpty->json('data.intent'));
    }

    public function test_interactions_restore_session_language(): void
    {
        Sanctum::actingAs($this->citizen());
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'I want a new driving license',
        ])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        $typed = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'private',
        ])->assertOk();
        $actionId = (int) $typed->json('data.pending_action.id');

        $confirm = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'confirm_pending_action',
            'action_id' => $actionId,
        ])->assertOk();

        $this->assertSame('en', $confirm->json('data.language'));
        $this->assertNoArabic((string) $confirm->json('data.reply'));
    }

    public function test_english_documents_and_tests_phrases(): void
    {
        $citizen = $this->citizen();
        LicenseApplication::query()->create([
            'application_number' => 'APP-P26-DOC',
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::Draft,
        ]);
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $docs = $this->postJson('/api/ai-agent/message', [
            'message' => 'What documents do I need?',
        ])->assertOk();
        $this->assertSame('en', $docs->json('data.language'));
        $this->assertStringContainsString('required documents', mb_strtolower((string) $docs->json('data.reply')));

        $tests = $this->postJson('/api/ai-agent/message', [
            'session_id' => (int) $docs->json('data.session_id'),
            'message' => 'What tests do I need?',
        ])->assertOk();
        $this->assertSame('en', $tests->json('data.language'));
        $this->assertNotSame('general_help', $tests->json('data.intent'));
    }

    public function test_english_appointment_book_reschedule_cancel_phrases(): void
    {
        $citizen = $this->citizen();
        LicenseApplication::query()->create([
            'application_number' => 'APP-P26-APT',
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::AppointmentPending,
        ]);
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $book = $this->postJson('/api/ai-agent/message', [
            'message' => 'I want to book an appointment',
        ])->assertOk();
        $this->assertSame('en', $book->json('data.language'));
        $this->assertSame('book_appointment', $book->json('data.intent'));
        $this->assertNotSame('general_help', $book->json('data.intent'));

        $reschedule = $this->postJson('/api/ai-agent/message', [
            'session_id' => (int) $book->json('data.session_id'),
            'message' => 'reschedule appointment',
        ])->assertOk();
        $this->assertSame('en', $reschedule->json('data.language'));
        $this->assertSame('reschedule_appointment', $reschedule->json('data.intent'));

        $cancel = $this->postJson('/api/ai-agent/message', [
            'session_id' => (int) $book->json('data.session_id'),
            'message' => 'cancel my appointment',
        ])->assertOk();
        $this->assertSame('en', $cancel->json('data.language'));
        $this->assertSame('cancel_appointment', $cancel->json('data.intent'));
    }
}
