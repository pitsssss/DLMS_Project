<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\TestResultStatus;
use App\Models\AuditLog;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use App\Modules\AIAgent\Services\AgentActionExecutor;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\Dashboard\Services\DashboardDocumentReviewService;
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
use Tests\Support\FakeDocumentFile;
use Tests\TestCase;

class AIAgentPhase1CriticalActionsTest extends TestCase
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

    private function mockGemini(?array $response = null, bool $throw = false): void
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

    private function createDraftApplication(User $citizen, string $applicationNumber = 'APP-PH1-DOCS'): LicenseApplication
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return LicenseApplication::query()->create([
            'application_number' => $applicationNumber,
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft->value,
        ]);
    }

    private function uploadAllRequiredDocuments(LicenseApplication $application): void
    {
        $checklist = $this->getJson("/api/applications/{$application->id}/required-documents")
            ->assertOk()
            ->json('data');

        foreach ($checklist as $required) {
            $fileName = 'doc-'.$required['code'].'.pdf';

            $this->post(
                "/api/applications/{$application->id}/documents",
                [
                    'required_document_id' => $required['id'],
                    'file' => FakeDocumentFile::pdf($fileName),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }
    }

    private function uploadRequiredDocumentByCode(LicenseApplication $application, string $code): void
    {
        $checklist = $this->getJson("/api/applications/{$application->id}/required-documents")
            ->assertOk()
            ->json('data');

        $doc = collect($checklist)->firstWhere('code', $code);
        $this->assertNotNull($doc, 'Required document code not found in checklist.');

        $fileName = 'doc-'.$code.'.pdf';

        $this->post(
            "/api/applications/{$application->id}/documents",
            [
                'required_document_id' => $doc['id'],
                'file' => FakeDocumentFile::pdf($fileName),
            ],
            ['Accept' => 'application/json']
        )->assertOk();
    }

    public function test_submit_documents_for_review_is_proposed_when_documents_are_complete(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-PH1-SUBMIT-OK');

        $this->uploadAllRequiredDocuments($application);

        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();

        $this->assertEquals('submit_documents_for_review', $response->json('data.pending_action.name'));
        $this->assertEquals('awaiting_confirmation', $response->json('data.pending_action.status'));
        $this->assertEquals($application->id, (int) $response->json('data.pending_action.arguments.application_id'));
    }

    public function test_submit_documents_for_review_is_not_proposed_when_required_documents_are_missing(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-PH1-SUBMIT-MISSING');

        // Upload only one required document to force "missing".
        $this->uploadRequiredDocumentByCode($application, 'national_id_copy');

        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'خلصت رفع الأوراق للمراجعة',
        ])->assertOk();

        $this->assertNull($response->json('data.pending_action'));
        $this->assertStringContainsString('الوثائق المطلوبة غير مكتملة', (string) $response->json('data.reply'));
        $this->assertStringContainsString('صورة شخصية', (string) $response->json('data.reply'));
    }

    public function test_confirm_submit_documents_for_review_transitions_application_status_and_creates_review_queue_entry(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-PH1-SUBMIT-EXEC');

        $this->uploadAllRequiredDocuments($application);

        $this->mockGemini(null);

        $messageResponse = $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();

        $actionId = (int) $messageResponse->json('data.pending_action.id');

        $confirmResponse = $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();

        $this->assertEquals('executed', $confirmResponse->json('data.action.status'));
        $this->assertEquals(ApplicationStatus::DocumentsUnderReview->value, $confirmResponse->json('data.result.status'));

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsUnderReview, $application->status);

        $dashboard = app(DashboardDocumentReviewService::class);
        $stats = $dashboard->stats();
        $this->assertGreaterThanOrEqual(1, (int) ($stats['awaiting_review'] ?? 0));
    }

    public function test_cancel_submit_documents_for_review_does_not_change_application_or_create_audit_notification_or_queue(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-PH2-CANCEL');
        $this->uploadAllRequiredDocuments($application);

        $dashboard = app(DashboardDocumentReviewService::class);
        $statsBefore = $dashboard->stats();

        $auditsBefore = AuditLog::query()
            ->where('action', 'application.status_changed')
            ->where('entity_id', $application->id)
            ->count();

        $notificationsBefore = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'application.documents_under_review')
            ->count();

        $this->mockGemini(null);
        $messageResponse = $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();

        $actionId = (int) $messageResponse->json('data.pending_action.id');
        $this->assertNotNull($actionId);

        $pendingAction = \App\Modules\AIAgent\Models\AIAgentAction::query()->findOrFail($actionId);
        $this->assertSame('awaiting_confirmation', $pendingAction->status->value);

        $this->postJson("/api/ai-agent/actions/{$actionId}/cancel")->assertOk()
            ->assertJsonPath('data.action.status', 'cancelled');

        $application->refresh();
        $this->assertSame(ApplicationStatus::Draft, $application->status);

        $statsAfter = $dashboard->stats();
        $this->assertSame($statsBefore['awaiting_review'] ?? 0, $statsAfter['awaiting_review'] ?? 0);

        $auditsAfter = AuditLog::query()
            ->where('action', 'application.status_changed')
            ->where('entity_id', $application->id)
            ->count();
        $this->assertSame($auditsBefore, $auditsAfter);

        $notificationsAfter = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'application.documents_under_review')
            ->count();
        $this->assertSame($notificationsBefore, $notificationsAfter);

        $this->assertDatabaseMissing('ai_agent_actions', [
            'id' => $actionId,
            'status' => AgentActionStatus::Executed->value,
        ]);

        $this->assertSame(0, LicenseApplication::query()->whereKey($application->id)->where('status', ApplicationStatus::DocumentsUnderReview->value)->count());
    }

    public function test_confirm_submit_documents_for_review_creates_audit_and_notification_once(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-PH2-AUDIT');
        $this->uploadAllRequiredDocuments($application);

        $this->mockGemini(null);

        $auditsBefore = AuditLog::query()
            ->where('action', 'application.status_changed')
            ->where('entity_id', $application->id)
            ->count();

        $notificationsBefore = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'application.documents_under_review')
            ->count();

        $messageResponse = $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();

        $actionId = (int) $messageResponse->json('data.pending_action.id');

        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::DocumentsUnderReview, $application->status);

        $this->assertDatabaseHas('application_status_histories', [
            'application_id' => $application->id,
            'new_status' => ApplicationStatus::DocumentsUnderReview->value,
        ]);

        $auditsAfter = AuditLog::query()
            ->where('action', 'application.status_changed')
            ->where('entity_id', $application->id)
            ->count();
        $this->assertSame($auditsBefore + 1, $auditsAfter);

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'application.status_changed')
                ->where('entity_id', $application->id)
                ->where('new_values->status', ApplicationStatus::DocumentsUnderReview->value)
                ->count()
        );

        $notificationsAfter = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'application.documents_under_review')
            ->count();
        $this->assertSame($notificationsBefore + 1, $notificationsAfter);
    }

    public function test_confirm_submit_documents_for_review_fails_when_application_state_changes_before_confirmation(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-PH1-STALe-STATUS');
        $this->uploadAllRequiredDocuments($application);

        $this->mockGemini(null);

        $messageResponse = $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();

        $actionId = (int) $messageResponse->json('data.pending_action.id');

        // Simulate an external state change (REST or employee panel) before user confirms.
        $application->status = ApplicationStatus::DocumentsUnderReview->value;
        $application->save();

        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")
            ->assertStatus(422);

        $action = AIAgentAction::query()->findOrFail($actionId);
        $this->assertSame(AgentActionStatus::Failed, $action->status);
        $this->assertNotNull($action->error_message);
    }

    public function test_submit_documents_for_review_fails_when_required_documents_become_rejected_before_confirmation(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $application = $this->createDraftApplication($citizen, 'APP-PH1-STALe-REJECT');
        $this->uploadAllRequiredDocuments($application);

        $this->mockGemini(null);

        $messageResponse = $this->postJson('/api/ai-agent/message', [
            'message' => 'أرسل الوثائق للمراجعة',
        ])->assertOk();

        $actionId = (int) $messageResponse->json('data.pending_action.id');

        // Force one required document to be rejected before confirm.
        $doc = \App\Models\ApplicationDocument::query()
            ->where('application_id', $application->id)
            ->firstOrFail();

        $doc->status = DocumentStatus::Rejected;
        $doc->save();

        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")
            ->assertStatus(422);

        $action = AIAgentAction::query()->findOrFail($actionId);
        $this->assertSame(AgentActionStatus::Failed, $action->status);
    }

    public function test_get_test_results_executes_immediately_and_returns_ordered_results(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-PH1-TESTRESULTS',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::InTesting->value,
        ]);

        $testType = TestType::query()->where('code', 'vision')->firstOrFail();
        /** @var AppointmentSlot $slot */
        $slot = AppointmentSlot::query()
            ->where('test_type_id', $testType->id)
            ->where('is_active', true)
            ->value('id');

        $this->assertNotNull($slot, 'Appointment slot not found for seeded test type.');

        $employee = User::factory()->dashboardEmployee('employee')->create();

        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => (int) $slot,
            'test_type_id' => $testType->id,
            'status' => 'booked',
            'scheduled_at' => now(),
        ]);

        $t1 = now()->subDays(2);
        $t2 = now()->subDay();

        TestResult::query()->create([
            'application_id' => $application->id,
            'test_appointment_id' => $appointment->id,
            'test_type_id' => $testType->id,
            'result' => TestResultStatus::Failed,
            'attempt_number' => 1,
            'notes' => null,
            'recorded_by' => $employee->id,
            'recorded_at' => $t1,
        ]);

        TestResult::query()->create([
            'application_id' => $application->id,
            'test_appointment_id' => $appointment->id,
            'test_type_id' => $testType->id,
            'result' => TestResultStatus::Passed,
            'attempt_number' => 2,
            'notes' => null,
            'recorded_by' => $employee->id,
            'recorded_at' => $t2,
        ]);

        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو نتيجة الفحص',
        ])->assertOk();

        $this->assertEquals('get_test_results', $response->json('data.intent'));
        $this->assertEquals('executed', $response->json('data.executed_action.status'));
        $this->assertEquals('get_test_results', $response->json('data.executed_action.name'));

        $items = $response->json('data.result.items');
        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]['attempt_number']);
        $this->assertSame(2, $items[1]['attempt_number']);
        $this->assertStringNotContainsString('messages.', (string) $response->json('data.reply'));
    }

    public function test_get_test_results_returns_empty_results_when_no_results_exist(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        LicenseApplication::query()->create([
            'application_number' => 'APP-PH1-TESTRESULTS-EMPTY',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::InTesting->value,
        ]);

        $this->mockGemini(null);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'نتيجة الفحص',
        ])->assertOk();

        $this->assertEquals('get_test_results', $response->json('data.intent'));
        $this->assertSame([], $response->json('data.result.items'));
        $this->assertStringContainsString('لا توجد نتائج اختبار', (string) $response->json('data.reply'));
    }

    public function test_allowed_proposed_actions_have_real_executors(): void
    {
        foreach (AgentSafetyRules::ALLOWED_PROPOSED_ACTIONS as $actionName) {
            $this->assertContains(
                $actionName,
                AgentActionExecutor::SUPPORTED_ACTION_NAMES,
                "Allowed/proposable action '{$actionName}' does not have a corresponding executor."
            );
        }
    }

    public function test_reschedule_and_cancel_are_not_allowed_proposed_actions_in_phase1(): void
    {
        $this->assertFalse(AgentSafetyRules::isAllowedProposedAction('reschedule_appointment'));
        $this->assertFalse(AgentSafetyRules::isAllowedProposedAction('cancel_appointment'));
    }
}

