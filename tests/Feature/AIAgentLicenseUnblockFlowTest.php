<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Models\ApplicationDocument;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\AIAgent\Support\AgentWorkflowActionMap;
use App\Modules\AIAgent\Support\AgentWorkflowPhraseMatcher;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeDocumentFile;
use Tests\TestCase;

class AIAgentLicenseUnblockFlowTest extends TestCase
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

    /**
     * @return array{0: User, 1: License}
     */
    private function citizenWithBlockedLicense(?User $citizen = null, array $licenseOverrides = []): array
    {
        $citizen = $citizen ?? $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $originalApplication = LicenseApplication::query()->create([
            'application_number' => 'APP-AGU-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'issued_at' => now()->subYear(),
        ]);

        $license = License::query()->create(array_merge([
            'license_number' => 'LIC-AGU-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $originalApplication->id,
            'status' => LicenseStatus::Blocked,
            'issue_date' => now()->subYears(2)->toDateString(),
            'expiry_date' => now()->addYears(5)->toDateString(),
            'block_reason' => 'Administrative hold',
            'blocked_at' => now(),
        ], $licenseOverrides));

        return [$citizen, $license];
    }

    /**
     * @return array{0: User, 1: License}
     */
    private function citizenWithActiveLicense(): array
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $app = LicenseApplication::query()->create([
            'application_number' => 'APP-ACT-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'issued_at' => now()->subYears(2),
        ]);
        $license = License::query()->create([
            'license_number' => 'LIC-ACT-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $app->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(2)->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        return [$citizen, $license];
    }

    private function addUnpaidFine(User $citizen, License $license): Fine
    {
        return Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => $license->id,
            'amount' => '100.00',
            'currency' => 'USD',
            'reason' => 'Speeding',
            'status' => FineStatus::Unpaid,
            'issued_at' => now(),
        ]);
    }

    private function unblockServiceType(): ServiceType
    {
        return ServiceType::query()->where('code', 'license_unblock')->firstOrFail();
    }

    private function createdUnblockApplication(User $citizen): LicenseApplication
    {
        return LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->where('service_type_id', $this->unblockServiceType()->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<LicenseApplication>
     */
    private function unblockApplicationsFor(User $citizen)
    {
        return LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->where('service_type_id', $this->unblockServiceType()->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requiredChecklist(int $applicationId): array
    {
        return $this->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->json('data');
    }

    private function agentUpload(int $sessionId, int $applicationId, int $requiredDocumentId, $file)
    {
        return $this->post(
            "/api/ai-agent/sessions/{$sessionId}/documents",
            [
                'application_id' => $applicationId,
                'required_document_id' => $requiredDocumentId,
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );
    }

    private function uploadAllRequiredViaAgent(int $sessionId, int $applicationId): void
    {
        foreach ($this->requiredChecklist($applicationId) as $item) {
            $this->agentUpload(
                $sessionId,
                $applicationId,
                (int) $item['id'],
                FakeDocumentFile::pdf('doc-'.$item['code'].'.pdf')
            )->assertOk();
        }
    }

    private function approveAllDocumentsForApplication(int $applicationId): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('employee')->create());

        $pending = $this->getJson('/api/admin/documents/pending-review')->assertOk();
        $ids = collect($pending->json('data.items'))
            ->filter(fn (array $item): bool => (int) ($item['application']['id'] ?? $item['application_id'] ?? 0) === $applicationId)
            ->pluck('id')
            ->all();

        foreach ($ids as $documentId) {
            $this->postJson("/api/admin/documents/{$documentId}/approve")->assertOk();
        }
    }

    private function completePayment(User $citizen, int $applicationId): void
    {
        Sanctum::actingAs($citizen);
        $paymentId = (int) $this->postJson("/api/applications/{$applicationId}/payments", [])->json('data.id');
        $this->postJson("/api/applications/{$applicationId}/payments/{$paymentId}/confirm", [])->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function arabicUnblockPhrases(): array
    {
        return [
            'بدي فك حظر رخصتي' => ['بدي فك حظر رخصتي'],
            'فك حظر الرخصة' => ['فك حظر الرخصة'],
            'رخصتي محظورة وبدي فك الحظر' => ['رخصتي محظورة وبدي فك الحظر'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function englishUnblockPhrases(): array
    {
        return [
            'I want to unblock my license' => ['I want to unblock my license'],
            'unblock my license' => ['unblock my license'],
            'request license unblock' => ['request license unblock'],
        ];
    }

    #[DataProvider('arabicUnblockPhrases')]
    public function test_arabic_phrases_resolve_to_license_unblock_intent(string $message): void
    {
        $this->assertSame(
            AgentIntent::CreateLicenseUnblockApplication,
            AgentWorkflowPhraseMatcher::resolveIntent($message)
        );
        $this->assertNotSame(AgentIntent::AdminActionDenied, AgentWorkflowPhraseMatcher::resolveIntent($message));

        [$citizen] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', ['message' => $message])->assertOk();
        $this->assertSame('create_license_unblock_application', $response->json('data.intent'));
        $this->assertSame('ar', $response->json('data.language'));
        $this->assertNotSame('admin_action_denied', $response->json('data.intent'));
    }

    #[DataProvider('englishUnblockPhrases')]
    public function test_english_phrases_resolve_to_license_unblock_intent(string $message): void
    {
        $this->assertSame(
            AgentIntent::CreateLicenseUnblockApplication,
            AgentWorkflowPhraseMatcher::resolveIntent($message)
        );
        $this->assertNotSame(AgentIntent::AdminActionDenied, AgentWorkflowPhraseMatcher::resolveIntent($message));

        [$citizen] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', ['message' => $message])->assertOk();
        $this->assertSame('create_license_unblock_application', $response->json('data.intent'));
        $this->assertSame('en', $response->json('data.language'));
        $this->assertNotSame('admin_action_denied', $response->json('data.intent'));
    }

    public function test_admin_direct_unblock_phrases_remain_denied(): void
    {
        foreach ([
            'Unblock citizen license 123 now',
            'force unblock this citizen\'s license',
            'unblock license 123 immediately',
            'فك حظر رخصة المواطن رقم 123',
        ] as $message) {
            $this->assertSame(
                AgentIntent::AdminActionDenied,
                AgentWorkflowPhraseMatcher::resolveIntent($message),
                $message
            );
        }

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'Unblock citizen license 123 now',
        ])->assertOk();
        $this->assertSame('admin_action_denied', $response->json('data.intent'));
        $this->assertStringContainsString('authorized employee', (string) $response->json('data.reply'));
    }

    public function test_no_blocked_license_returns_localized_none_eligible(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي فك حظر رخصتي',
        ])->assertOk();

        $this->assertSame('create_license_unblock_application', $response->json('data.intent'));
        $this->assertSame('no_eligible_license', $response->json('data.message_type'));
        $this->assertNull($response->json('data.pending_action'));
        $this->assertStringContainsString('رخصة محظورة', (string) $response->json('data.reply'));
        $this->assertStringNotContainsString('messages.', (string) $response->json('data.reply'));
        $this->assertSame(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->whereHas('serviceType', fn ($q) => $q->where('code', 'license_unblock'))->count());
    }

    public function test_active_license_only_is_not_eligible_for_unblock(): void
    {
        [$citizen] = $this->citizenWithActiveLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'I want to unblock my license',
        ])->assertOk();

        $this->assertSame('create_license_unblock_application', $response->json('data.intent'));
        $this->assertSame('no_eligible_license', $response->json('data.message_type'));
        $this->assertStringContainsString('eligible blocked license', (string) $response->json('data.reply'));
    }

    public function test_unpaid_fines_block_unblock_with_domain_message(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $this->addUnpaidFine($citizen, $license);
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي فك حظر رخصتي',
        ])->assertOk();

        $this->assertSame('create_license_unblock_application', $response->json('data.intent'));
        $this->assertNull($response->json('data.pending_action'));
        $reply = (string) $response->json('data.reply');
        $this->assertStringContainsString('الغرامات', $reply);
        $this->assertStringContainsString('المخالفات', $reply);
        $this->assertSame(0, $this->unblockApplicationsFor($citizen)->count());
    }

    public function test_single_blocked_license_is_auto_selected_and_requires_confirmation(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي فك حظر رخصتي',
        ])->assertOk();

        $this->assertSame('create_license_unblock_application', $ask->json('data.intent'));
        $this->assertTrue((bool) $ask->json('data.requires_confirmation'));
        $this->assertSame('create_application', $ask->json('data.pending_action.name'));
        $this->assertSame('license_unblock', $ask->json('data.pending_action.arguments.service_type_code'));
        $this->assertSame($license->id, (int) $ask->json('data.pending_action.arguments.related_license_id'));
        $this->assertSame(0, LicenseApplication::query()->where('service_type_id', ServiceType::query()->where('code', 'license_unblock')->value('id'))->count());

        $sessionId = (int) $ask->json('data.session_id');
        $confirm = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'نعم',
        ])->assertOk();

        $this->assertNotEmpty($confirm->json('data.result.application_number'));
        $this->assertStringContainsString('فك حظر', (string) $confirm->json('data.reply'));
        $this->assertStringContainsString('الوثائق', (string) $confirm->json('data.reply'));
        $this->assertStringNotContainsString('unblocked your license', mb_strtolower((string) $confirm->json('data.reply')));

        $application = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->where('related_license_id', $license->id)
            ->firstOrFail();
        $this->assertSame('license_unblock', $application->serviceType()->value('code'));
        $this->assertSame(ApplicationStatus::Draft, $application->status);
    }

    public function test_english_yes_confirms_unblock_application(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'I want to unblock my license',
        ])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        $confirm = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'yes',
        ])->assertOk();

        $this->assertSame('en', $confirm->json('data.language'));
        $this->assertNotEmpty($confirm->json('data.result.application_number'));
        $this->assertStringContainsString('unblock', mb_strtolower((string) $confirm->json('data.reply')));
        $this->assertSame($license->id, (int) $this->createdUnblockApplication($citizen)->related_license_id);
    }

    public function test_cancel_does_not_create_unblock_application(): void
    {
        [$citizen] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');

        $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'لا',
        ])->assertOk();

        $this->assertSame(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->whereHas('serviceType', fn ($q) => $q->where('code', 'license_unblock'))->count());

        [$enCitizen] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($enCitizen);
        $enAsk = $this->postJson('/api/ai-agent/message', ['message' => 'unblock my license'])->assertOk();
        $this->postJson('/api/ai-agent/message', [
            'session_id' => (int) $enAsk->json('data.session_id'),
            'message' => 'cancel',
        ])->assertOk();
        $this->assertSame(0, LicenseApplication::query()->where('citizen_id', $enCitizen->id)->whereHas('serviceType', fn ($q) => $q->where('code', 'license_unblock'))->count());
    }

    public function test_multiple_blocked_licenses_require_selection_and_reject_foreign_token(): void
    {
        [$citizen, $first] = $this->citizenWithBlockedLicense();
        [, $second] = $this->citizenWithBlockedLicense($citizen);
        [$otherCitizen, $foreign] = $this->citizenWithBlockedLicense();

        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي فك حظر رخصتي',
        ])->assertOk();

        $this->assertSame('license_selection_required', $ask->json('data.message_type'));
        $this->assertCount(2, $ask->json('data.ui_payload.licenses'));
        $this->assertNull($ask->json('data.pending_action'));
        $sessionId = (int) $ask->json('data.session_id');

        $selected = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'الأولى',
        ])->assertOk();

        $this->assertSame('create_application', $selected->json('data.pending_action.name'));
        $this->assertSame('license_unblock', $selected->json('data.pending_action.arguments.service_type_code'));
        $this->assertContains(
            (int) $selected->json('data.pending_action.arguments.related_license_id'),
            [$first->id, $second->id]
        );
        $this->assertNotSame($foreign->id, (int) $selected->json('data.pending_action.arguments.related_license_id'));

        Sanctum::actingAs($otherCitizen);
        $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_license',
            'selection_token' => (string) $ask->json('data.ui_payload.licenses.0.selection_token'),
        ])->assertNotFound();
    }

    public function test_duplicate_active_unblock_application_is_not_recreated(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $this->postJson('/api/ai-agent/message', [
            'session_id' => (int) $ask->json('data.session_id'),
            'message' => 'نعم',
        ])->assertOk();

        $again = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $this->assertNotSame('create_application', $again->json('data.pending_action.name'));
        $this->assertSame(
            1,
            LicenseApplication::query()
                ->where('citizen_id', $citizen->id)
                ->where('related_license_id', $license->id)
                ->count()
        );
        $this->assertStringContainsString('طلب', (string) $again->json('data.reply'));
    }

    public function test_stale_confirmation_revalidates_unpaid_fines(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $actionId = (int) $ask->json('data.pending_action.id');

        $this->addUnpaidFine($citizen, $license);

        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertStatus(422);
        $this->assertSame(0, LicenseApplication::query()->where('citizen_id', $citizen->id)->whereHas('serviceType', fn ($q) => $q->where('code', 'license_unblock'))->count());
    }

    public function test_required_documents_and_session_upload_and_submit_for_unblock(): void
    {
        Storage::fake('local');
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'نعم',
        ])->assertOk();

        $application = $this->createdUnblockApplication($citizen);

        $docs = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو الوثائق المطلوبة؟',
        ])->assertOk();
        $this->assertSame('get_required_documents', $docs->json('data.intent'));
        $codes = collect($docs->json('data.result.required_documents'))->pluck('code')->all();
        $this->assertContains('national_id_copy', $codes);
        $this->assertContains('fine_clearance', $codes);

        $this->uploadAllRequiredViaAgent($sessionId, $application->id);

        $submit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();
        $this->assertSame('submit_documents_for_review', $submit->json('data.pending_action.name'));
        $this->postJson('/api/ai-agent/actions/'.(int) $submit->json('data.pending_action.id').'/confirm')->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsUnderReview, $application->status);
        $this->assertSame($license->id, (int) $application->related_license_id);
    }

    public function test_rejected_document_can_be_replaced_and_resubmitted_for_unblock(): void
    {
        Storage::fake('local');
        [$citizen] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'نعم',
        ])->assertOk();
        $application = $this->createdUnblockApplication($citizen);
        $this->uploadAllRequiredViaAgent($sessionId, $application->id);

        $submit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();
        $this->postJson('/api/ai-agent/actions/'.(int) $submit->json('data.pending_action.id').'/confirm')->assertOk();

        $employee = User::factory()->dashboardEmployee('employee')->create();
        Sanctum::actingAs($employee);
        $firstDocId = (int) $this->getJson('/api/admin/documents/pending-review')->json('data.items.0.id');
        $rejectedDoc = ApplicationDocument::query()->findOrFail($firstDocId);
        $this->postJson("/api/admin/documents/{$firstDocId}/reject", [
            'rejection_reason' => 'Illegible scan.',
        ])->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsRejected, $application->status);

        Sanctum::actingAs($citizen);
        $this->agentUpload(
            $sessionId,
            $application->id,
            (int) $rejectedDoc->required_document_id,
            FakeDocumentFile::pdf('replacement.pdf')
        )->assertOk();

        $resubmit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();
        $this->postJson('/api/ai-agent/actions/'.(int) $resubmit->json('data.pending_action.id').'/confirm')->assertOk();
        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsUnderReview, $application->status);
        $this->assertSame(
            DocumentStatus::PendingReview,
            ApplicationDocument::query()
                ->where('application_id', $application->id)
                ->where('required_document_id', $rejectedDoc->required_document_id)
                ->latest('id')
                ->firstOrFail()
                ->status
        );
    }

    public function test_payment_pending_fee_and_start_payment_do_not_mark_paid(): void
    {
        Storage::fake('local');
        [$citizen] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->postJson('/api/ai-agent/message', ['session_id' => $sessionId, 'message' => 'نعم'])->assertOk();
        $application = $this->createdUnblockApplication($citizen);
        $this->uploadAllRequiredViaAgent($sessionId, $application->id);
        $submit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();
        $this->postJson('/api/ai-agent/actions/'.(int) $submit->json('data.pending_action.id').'/confirm')->assertOk();
        $this->approveAllDocumentsForApplication($application->id);

        Sanctum::actingAs($citizen);
        $fee = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو الرسوم',
        ])->assertOk();
        $this->assertSame('get_application_fee', $fee->json('data.intent'));
        $this->assertSame('unblock_fee', $fee->json('data.result.fee.code'));

        $pay = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'بدي ادفع',
        ])->assertOk();
        $this->assertSame('start_payment', $pay->json('data.intent'));
        $this->assertTrue((bool) $pay->json('data.requires_confirmation'));
        $confirm = $this->postJson('/api/ai-agent/actions/'.(int) $pay->json('data.pending_action.id').'/confirm')->assertOk();
        $this->assertNotNull($confirm->json('data.result.payment_id'));
        $this->assertNotSame('completed', $confirm->json('data.result.status'));

        $status = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'حالة الدفع',
        ])->assertOk();
        $this->assertTrue((bool) $status->json('data.result.is_awaiting_payment'));
        $this->assertFalse((bool) $status->json('data.result.is_paid'));
        $application->refresh();
        $this->assertSame(ApplicationStatus::PaymentPending, $application->status);
    }

    public function test_approved_unblock_waits_for_employee_and_does_not_mutate_license(): void
    {
        Storage::fake('local');
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->postJson('/api/ai-agent/message', ['session_id' => $sessionId, 'message' => 'نعم'])->assertOk();
        $application = $this->createdUnblockApplication($citizen);
        $this->uploadAllRequiredViaAgent($sessionId, $application->id);
        $submit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();
        $this->postJson('/api/ai-agent/actions/'.(int) $submit->json('data.pending_action.id').'/confirm')->assertOk();
        $this->approveAllDocumentsForApplication($application->id);
        $this->completePayment($citizen, $application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertSame(LicenseStatus::Blocked, $license->fresh()->status);

        Sanctum::actingAs($citizen);
        $status = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();
        $this->assertStringContainsString('الموظف', (string) $status->json('data.reply'));
        $this->assertStringContainsString('فك الحظر', (string) $status->json('data.result.next_step_message'));

        $next = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو لازم أعمل هلق؟',
        ])->assertOk();
        $this->assertStringContainsString('الموظف المختص', (string) $next->json('data.reply'));

        $this->assertFalse(in_array('unblock_license', AgentSafetyRules::PHASE_9B_EXECUTABLE_ACTIONS, true));
        $this->assertFalse(in_array('request_unblock', AgentSafetyRules::ALLOWED_PROPOSED_ACTIONS, true));
        $this->assertFalse(in_array('request_unblock', AgentSafetyRules::PHASE_9B_EXECUTABLE_ACTIONS, true));
        $this->assertSame(LicenseStatus::Blocked, $license->fresh()->status);
    }

    public function test_completed_unblock_is_terminal_and_licenses_reflect_backend_state(): void
    {
        Storage::fake('local');
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->postJson('/api/ai-agent/message', ['session_id' => $sessionId, 'message' => 'نعم'])->assertOk();
        $application = $this->createdUnblockApplication($citizen);
        $this->uploadAllRequiredViaAgent($sessionId, $application->id);
        $submit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();
        $this->postJson('/api/ai-agent/actions/'.(int) $submit->json('data.pending_action.id').'/confirm')->assertOk();
        $this->approveAllDocumentsForApplication($application->id);
        $this->completePayment($citizen, $application->id);

        Sanctum::actingAs(User::factory()->dashboardEmployee('license_employee')->create());
        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")->assertOk();

        $application->refresh();
        $license->refresh();
        $this->assertSame(ApplicationStatus::Completed, $application->status);
        $this->assertContains($license->status, [LicenseStatus::Active, LicenseStatus::Expired]);

        Sanctum::actingAs($citizen);
        $status = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();
        $this->assertStringContainsString('إكمال', (string) $status->json('data.reply'));
        $this->assertStringContainsString('لا توجد خطوات', (string) $status->json('data.result.next_step_message'));

        $licenses = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'رخصي',
        ])->assertOk();
        $this->assertSame('get_licenses', $licenses->json('data.intent'));
        $statuses = collect($licenses->json('data.result.items'))->pluck('status')->all();
        $this->assertContains($license->status->value, $statuses);
    }

    public function test_rejected_unblock_application_is_explained_and_license_stays_blocked(): void
    {
        Storage::fake('local');
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->postJson('/api/ai-agent/message', ['session_id' => $sessionId, 'message' => 'نعم'])->assertOk();
        $application = $this->createdUnblockApplication($citizen);
        $this->uploadAllRequiredViaAgent($sessionId, $application->id);
        $submit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();
        $this->postJson('/api/ai-agent/actions/'.(int) $submit->json('data.pending_action.id').'/confirm')->assertOk();
        $this->approveAllDocumentsForApplication($application->id);
        $this->completePayment($citizen, $application->id);

        Sanctum::actingAs(User::factory()->dashboardEmployee('license_employee')->create());
        $this->postJson("/api/dashboard/applications/{$application->id}/reject", [
            'reason' => 'Insufficient supporting evidence',
        ])->assertOk();

        $this->assertSame(LicenseStatus::Blocked, $license->fresh()->status);

        Sanctum::actingAs($citizen);
        $status = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'شو حالة طلبي؟',
        ])->assertOk();
        $reply = (string) $status->json('data.reply');
        $this->assertStringContainsString('رفض', $reply);
        $this->assertStringContainsString('Insufficient supporting evidence', (string) $status->json('data.result.next_step_message'));
    }

    public function test_english_status_phrases_for_approved_and_completed(): void
    {
        Storage::fake('local');
        [$citizen] = $this->citizenWithBlockedLicense();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'I want to unblock my license'])->assertOk();
        $sessionId = (int) $ask->json('data.session_id');
        $this->postJson('/api/ai-agent/message', ['session_id' => $sessionId, 'message' => 'yes'])->assertOk();
        $application = $this->createdUnblockApplication($citizen);
        $this->uploadAllRequiredViaAgent($sessionId, $application->id);
        $submit = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'submit documents for review',
        ])->assertOk();
        $this->postJson('/api/ai-agent/actions/'.(int) $submit->json('data.pending_action.id').'/confirm')->assertOk();
        $this->approveAllDocumentsForApplication($application->id);
        $this->completePayment($citizen, $application->id);

        Sanctum::actingAs($citizen);
        $status = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'What is my application status?',
        ])->assertOk();
        $this->assertSame('en', $status->json('data.language'));
        $this->assertStringContainsString('authorized employee', (string) $status->json('data.result.next_step_message'));

        $docs = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'What documents do I need?',
        ])->assertOk();
        $this->assertSame('en', $docs->json('data.language'));
    }

    public function test_ownership_idor_and_employee_cannot_use_citizen_agent(): void
    {
        [$owner] = $this->citizenWithBlockedLicense();
        $other = $this->citizen();
        Sanctum::actingAs($owner);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي فك حظر رخصتي'])->assertOk();
        $actionId = (int) $ask->json('data.pending_action.id');
        $sessionId = (int) $ask->json('data.session_id');

        Sanctum::actingAs($other);
        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertNotFound();
        $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'نعم',
        ])->assertNotFound();

        $employee = User::factory()->dashboardEmployee('employee')->create();
        Sanctum::actingAs($employee);
        $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي فك حظر رخصتي',
        ])->assertForbidden();
    }

    public function test_renew_lost_damaged_and_new_license_still_work(): void
    {
        [$citizen, $license] = $this->citizenWithActiveLicense();
        $license->update([
            'issue_date' => now()->subYears(9)->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $renew = $this->postJson('/api/ai-agent/message', ['message' => 'بدي جدد رخصتي'])->assertOk();
        $this->assertSame('create_renew_license_application', $renew->json('data.intent'));
        $this->assertSame('renew_license', $renew->json('data.pending_action.arguments.service_type_code'));

        $lost = $this->postJson('/api/ai-agent/message', ['message' => 'ضاعت رخصتي'])->assertOk();
        $this->assertSame('create_lost_replacement_application', $lost->json('data.intent'));

        $damaged = $this->postJson('/api/ai-agent/message', ['message' => 'رخصتي تالفة'])->assertOk();
        $this->assertSame('create_damaged_replacement_application', $damaged->json('data.intent'));

        $new = $this->postJson('/api/ai-agent/message', ['message' => 'بدي رخصة جديدة'])->assertOk();
        $this->assertSame('create_new_license_application', $new->json('data.intent'));
    }

    public function test_no_new_unsupported_executor_action_was_introduced(): void
    {
        $this->assertContains('create_application', AgentSafetyRules::PHASE_9B_EXECUTABLE_ACTIONS);
        $this->assertNotContains('request_unblock', AgentSafetyRules::PHASE_9B_EXECUTABLE_ACTIONS);
        $this->assertNotContains('request_unblock', AgentSafetyRules::ALLOWED_PROPOSED_ACTIONS);
        $this->assertContains('request_unblock', AgentWorkflowActionMap::MUTATING_ACTIONS);
        $this->assertContains('unblock_license', AgentSafetyRules::ADMIN_ONLY_ACTIONS);
        $this->assertSame(
            AgentIntent::CreateLicenseUnblockApplication->value,
            'create_license_unblock_application'
        );
    }
}
