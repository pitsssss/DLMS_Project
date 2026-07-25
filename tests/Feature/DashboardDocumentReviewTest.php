<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentRejectionReason;
use App\Enums\DocumentStatus;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardDocumentReviewTest extends TestCase
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
        Storage::fake('local');
    }

    private function readyCitizen(array $overrides = []): User
    {
        $suffix = (string) str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

        return User::factory()->withApprovedProfile()->create(array_merge([
            'email_verified_at' => now(),
            'name' => 'مواطن المراجعة',
            'phone' => '09'.$suffix,
            'national_id' => '1'.$suffix,
            'email' => 'review-citizen-'.uniqid('', true).'@example.com',
        ], $overrides));
    }

    private function reviewer(): User
    {
        return User::factory()->dashboardEmployee('profile_document_reviewer')->create();
    }

    private function employeeWithoutPermission(): User
    {
        return User::factory()->dashboardEmployee('fines_employee')->create();
    }

    private function createSubmittedApplication(User $citizen): int
    {
        Sanctum::actingAs($citizen);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $applicationId = (int) $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->assertOk()->json('data.id');

        $this->uploadAllRequired($citizen, $applicationId);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        return $applicationId;
    }

    private function uploadAllRequired(User $citizen, int $applicationId): void
    {
        Sanctum::actingAs($citizen);
        $checklist = $this->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->json('data');

        foreach ($checklist as $item) {
            $this->post(
                "/api/applications/{$applicationId}/documents",
                [
                    'required_document_id' => $item['id'],
                    'file' => UploadedFile::fake()->create('doc-'.$item['code'].'.pdf', 80, 'application/pdf'),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }
    }

    private function firstPendingDocumentId(int $applicationId): int
    {
        return (int) ApplicationDocument::query()
            ->where('application_id', $applicationId)
            ->where('status', DocumentStatus::PendingReview)
            ->orderBy('id')
            ->value('id');
    }

    private function pendingDocumentIds(int $applicationId): array
    {
        return ApplicationDocument::query()
            ->where('application_id', $applicationId)
            ->where('status', DocumentStatus::PendingReview)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public function test_unauthenticated_queue_returns_401(): void
    {
        $this->getJson('/api/dashboard/document-reviews')->assertUnauthorized();
    }

    public function test_citizen_cannot_access_document_review_endpoints(): void
    {
        $citizen = $this->readyCitizen();
        Sanctum::actingAs($citizen);

        $this->getJson('/api/dashboard/document-reviews')->assertForbidden();
        $this->getJson('/api/dashboard/document-reviews/stats')->assertForbidden();
    }

    public function test_employee_without_review_documents_permission_gets_403(): void
    {
        Sanctum::actingAs($this->employeeWithoutPermission());

        $this->getJson('/api/dashboard/document-reviews')->assertForbidden();
        $this->getJson('/api/dashboard/document-reviews/stats')->assertForbidden();
    }

    public function test_authorized_reviewer_can_access_queue_and_stats(): void
    {
        Sanctum::actingAs($this->reviewer());

        $this->getJson('/api/dashboard/document-reviews')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'stats' => ['awaiting_review', 'completed_documents', 'late_requests', 'reupload_required'],
                    'items',
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);

        $this->getJson('/api/dashboard/document-reviews/stats')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_default_queue_is_awaiting_review_and_excludes_later_stages(): void
    {
        $citizen = $this->readyCitizen();
        $awaitingId = $this->createSubmittedApplication($citizen);

        $paymentApp = LicenseApplication::query()->findOrFail($awaitingId)->replicate();
        $paymentApp->application_number = 'APP-PAY-TEST-001';
        $paymentApp->status = ApplicationStatus::PaymentPending;
        $paymentApp->submitted_at = now()->subDay();
        $paymentApp->save();

        $testingApp = $paymentApp->replicate();
        $testingApp->application_number = 'APP-TEST-STAGE-001';
        $testingApp->status = ApplicationStatus::InTesting;
        $testingApp->save();

        $issuedApp = $paymentApp->replicate();
        $issuedApp->application_number = 'APP-ISSUED-001';
        $issuedApp->status = ApplicationStatus::LicenseIssued;
        $issuedApp->save();

        Sanctum::actingAs($this->reviewer());
        $response = $this->getJson('/api/dashboard/document-reviews')->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();

        $this->assertContains($awaitingId, $ids);
        $this->assertNotContains($paymentApp->id, $ids);
        $this->assertNotContains($testingApp->id, $ids);
        $this->assertNotContains($issuedApp->id, $ids);
        $this->assertSame('awaiting_review', $response->json('data.items.0.review_status.value'));
    }

    public function test_queue_does_not_expose_pii_and_search_ignores_phone_email_national_id(): void
    {
        $citizen = $this->readyCitizen([
            'name' => 'أحمد الوثائقي',
            'phone' => '0988777666',
            'national_id' => '99887766554',
            'email' => 'secret-pii@example.com',
        ]);
        $applicationId = $this->createSubmittedApplication($citizen);
        $applicationNumber = LicenseApplication::query()->findOrFail($applicationId)->application_number;

        Sanctum::actingAs($this->reviewer());

        $queue = $this->getJson('/api/dashboard/document-reviews')->assertOk();
        $item = collect($queue->json('data.items'))->firstWhere('id', $applicationId);
        $this->assertNotNull($item);
        $this->assertSame(['id', 'name'], array_keys($item['citizen']));
        $this->assertArrayNotHasKey('phone', $item['citizen']);
        $this->assertArrayNotHasKey('national_id', $item['citizen']);
        $this->assertArrayNotHasKey('email', $item['citizen']);
        $json = json_encode($item, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('0988777666', $json);
        $this->assertStringNotContainsString('99887766554', $json);
        $this->assertStringNotContainsString('secret-pii@example.com', $json);

        $this->getJson('/api/dashboard/document-reviews?search='.urlencode($applicationNumber))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->getJson('/api/dashboard/document-reviews?search='.urlencode('أحمد الوثائقي'))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->getJson('/api/dashboard/document-reviews?search=0988777666')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);

        $this->getJson('/api/dashboard/document-reviews?search=secret-pii@example.com')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);

        $this->getJson('/api/dashboard/document-reviews?search=99887766554')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_explicit_filters_service_license_pagination_and_invalid_status(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);

        Sanctum::actingAs($this->reviewer());

        $this->getJson('/api/dashboard/document-reviews?service_type_code=new_license')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->getJson('/api/dashboard/document-reviews?license_type_code=private')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->getJson('/api/dashboard/document-reviews?service_type_code=renew_license')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);

        $this->getJson('/api/dashboard/document-reviews?per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 1);

        $this->getJson('/api/dashboard/document-reviews?per_page=101')->assertStatus(422);
        $this->getJson('/api/dashboard/document-reviews?review_status=not_a_status')->assertStatus(422);

        LicenseApplication::query()->whereKey($applicationId)->update([
            'status' => ApplicationStatus::DocumentsRejected,
        ]);

        $this->getJson('/api/dashboard/document-reviews?review_status=reupload_required')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        LicenseApplication::query()->whereKey($applicationId)->update([
            'status' => ApplicationStatus::PaymentPending,
        ]);

        $this->getJson('/api/dashboard/document-reviews?review_status=completed')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        LicenseApplication::query()->whereKey($applicationId)->update([
            'status' => ApplicationStatus::DocumentsUnderReview,
            'submitted_at' => now()->subDays(4),
        ]);

        $this->getJson('/api/dashboard/document-reviews?review_status=late')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_queue_ordering_is_fifo_and_stats_units_are_applications(): void
    {
        $citizenA = $this->readyCitizen(['name' => 'أول']);
        $citizenB = $this->readyCitizen(['name' => 'ثاني']);
        $firstId = $this->createSubmittedApplication($citizenA);
        $secondId = $this->createSubmittedApplication($citizenB);

        LicenseApplication::query()->whereKey($firstId)->update(['submitted_at' => now()->subHours(2)]);
        LicenseApplication::query()->whereKey($secondId)->update(['submitted_at' => now()->subHour()]);

        Sanctum::actingAs($this->reviewer());
        $ids = collect($this->getJson('/api/dashboard/document-reviews')->json('data.items'))->pluck('id')->all();
        $this->assertSame([$firstId, $secondId], $ids);

        $stats = $this->getJson('/api/dashboard/document-reviews/stats')->assertOk()->json('data');
        $this->assertSame(2, $stats['awaiting_review']);
        $this->assertSame(0, $stats['completed_documents']);
        $this->assertSame(0, $stats['reupload_required']);
    }

    public function test_details_contract_actions_rejection_options_and_no_storage_path(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);

        Sanctum::actingAs($this->reviewer());
        $details = $this->getJson("/api/dashboard/document-reviews/{$applicationId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $this->assertArrayHasKey('summary', $details);
        $this->assertArrayHasKey('header', $details);
        $this->assertArrayHasKey('documents', $details);
        $this->assertSame(DocumentRejectionReason::options(), $details['rejection_reasons']);
        $this->assertArrayHasKey('id', $details['summary']['citizen']);
        $this->assertArrayHasKey('name', $details['summary']['citizen']);
        $this->assertArrayNotHasKey('phone', $details['summary']['citizen']);
        $this->assertArrayNotHasKey('national_id', $details['summary']['citizen']);

        $pending = collect($details['documents'])->first(
            fn (array $row) => ($row['latest_document']['status'] ?? null) === DocumentStatus::PendingReview->value
        );
        $this->assertTrue($pending['actions']['can_approve']);
        $this->assertTrue($pending['actions']['can_reject']);
        $this->assertNotNull($pending['actions']['document_id']);

        $encoded = json_encode($details, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('application_documents/', $encoded);
        $this->assertStringNotContainsString(storage_path(), $encoded);

        $this->getJson('/api/dashboard/document-reviews/999999')->assertStatus(404);
    }

    public function test_preview_allowed_types_headers_and_auth(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);
        $documentId = $this->firstPendingDocumentId($applicationId);
        $document = ApplicationDocument::query()->findOrFail($documentId);

        Storage::disk('local')->put($document->file_path, '%PDF-1.4 fake');

        Sanctum::actingAs($this->reviewer());
        $preview = $this->get("/api/dashboard/document-reviews/documents/{$documentId}/preview");
        $preview->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $preview->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $preview->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $preview->headers->get('X-Content-Type-Options'));
        $this->assertStringNotContainsString($document->file_path, $preview->getContent());

        Sanctum::actingAs($this->readyCitizen(['email' => 'other-citizen@example.com']));
        $this->get("/api/dashboard/document-reviews/documents/{$documentId}/preview")->assertForbidden();

        Sanctum::actingAs($this->employeeWithoutPermission());
        $this->get("/api/dashboard/document-reviews/documents/{$documentId}/preview")->assertForbidden();
    }

    public function test_preview_rejects_missing_file_traversal_outside_root_and_dangerous_mime(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);
        $documentId = $this->firstPendingDocumentId($applicationId);
        $document = ApplicationDocument::query()->findOrFail($documentId);

        Sanctum::actingAs($this->reviewer());
        Storage::disk('local')->delete($document->file_path);
        $this->get("/api/dashboard/document-reviews/documents/{$documentId}/preview")->assertStatus(404);

        $document->file_path = '../etc/passwd';
        $document->save();
        $this->get("/api/dashboard/document-reviews/documents/{$documentId}/preview")->assertStatus(404);

        $outside = base_path('composer.json');
        $document->file_path = $outside;
        $document->mime_type = 'application/pdf';
        $document->save();
        $this->get("/api/dashboard/document-reviews/documents/{$documentId}/preview")->assertStatus(404);

        $safeRelative = 'application_documents/'.$applicationId.'/safe.pdf';
        Storage::disk('local')->put($safeRelative, '%PDF-1.4 safe');
        $document->file_path = Storage::disk('local')->path($safeRelative);
        $document->mime_type = 'application/pdf';
        $document->save();
        $this->get("/api/dashboard/document-reviews/documents/{$documentId}/preview")->assertOk();

        $document->mime_type = 'application/x-msdownload';
        $document->save();
        Storage::disk('local')->put($safeRelative, 'MZ-fake-exe');
        $this->get("/api/dashboard/document-reviews/documents/{$documentId}/preview")->assertStatus(422);
    }

    public function test_approve_sets_fields_audit_notification_and_blocks_stale_second_decision(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);
        $documentId = $this->firstPendingDocumentId($applicationId);

        $reviewerA = $this->reviewer();
        $reviewerB = $this->reviewer();

        Sanctum::actingAs($reviewerA);
        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Approved->value)
            ->assertJsonPath('data.rejection', null);

        $document = ApplicationDocument::query()->findOrFail($documentId);
        $this->assertSame(DocumentStatus::Approved, $document->status);
        $this->assertSame($reviewerA->id, $document->reviewed_by);
        $this->assertNotNull($document->reviewed_at);
        $this->assertNull($document->rejection_reason);
        $this->assertNull($document->rejection_reason_code);
        $this->assertNull($document->rejection_details);

        $this->assertSame(1, AuditLog::query()->where('action', 'document.approved')->where('entity_id', $documentId)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $citizen->id)->where('type', 'document.approved')->count());

        Sanctum::actingAs($reviewerB);
        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => DocumentRejectionReason::UnclearDocument->value,
            'rejection_details' => 'محاولة متأخرة',
        ])->assertStatus(422);

        $document->refresh();
        $this->assertSame(DocumentStatus::Approved, $document->status);
        $this->assertSame($reviewerA->id, $document->reviewed_by);
        $this->assertSame(0, AuditLog::query()->where('action', 'document.rejected')->where('entity_id', $documentId)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $citizen->id)->where('type', 'document.rejected')->count());
        $this->assertDatabaseHas('license_applications', [
            'id' => $applicationId,
            'status' => ApplicationStatus::DocumentsUnderReview->value,
        ]);
    }

    public function test_final_required_approval_moves_to_payment_pending_once(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);
        $ids = $this->pendingDocumentIds($applicationId);
        $this->assertNotEmpty($ids);

        Sanctum::actingAs($this->reviewer());
        foreach ($ids as $index => $documentId) {
            $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/approve")->assertOk();
            if ($index < count($ids) - 1) {
                $this->assertDatabaseHas('license_applications', [
                    'id' => $applicationId,
                    'status' => ApplicationStatus::DocumentsUnderReview->value,
                ]);
            }
        }

        $this->assertDatabaseHas('license_applications', [
            'id' => $applicationId,
            'status' => ApplicationStatus::PaymentPending->value,
        ]);

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'application.status_changed')
                ->where('entity_id', $applicationId)
                ->where('new_values->status', ApplicationStatus::PaymentPending->value)
                ->count()
        );
    }

    public function test_reject_validation_structured_storage_notification_and_stale_approve(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);
        $documentId = $this->firstPendingDocumentId($applicationId);
        $reviewer = $this->reviewer();

        Sanctum::actingAs($reviewer);

        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason_code']);

        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => 'unknown_code',
        ])->assertStatus(422)->assertJsonValidationErrors(['rejection_reason_code']);

        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => DocumentRejectionReason::Other->value,
        ])->assertStatus(422)->assertJsonValidationErrors(['rejection_details']);

        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => DocumentRejectionReason::Other->value,
            'rejection_details' => '   ',
        ])->assertStatus(422)->assertJsonValidationErrors(['rejection_details']);

        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => DocumentRejectionReason::UnclearDocument->value,
            'rejection_details' => str_repeat('x', 2001),
        ])->assertStatus(422)->assertJsonValidationErrors(['rejection_details']);

        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => DocumentRejectionReason::UnclearDocument->value,
            'rejection_details' => 'الصورة غير واضحة في الزاوية.',
        ])->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Rejected->value)
            ->assertJsonPath('data.rejection.code', DocumentRejectionReason::UnclearDocument->value)
            ->assertJsonPath('data.rejection.details', 'الصورة غير واضحة في الزاوية.');

        $document = ApplicationDocument::query()->findOrFail($documentId);
        $this->assertSame(DocumentStatus::Rejected, $document->status);
        $this->assertSame(DocumentRejectionReason::UnclearDocument->value, $document->rejection_reason_code);
        $this->assertSame('الصورة غير واضحة في الزاوية.', $document->rejection_details);
        $this->assertSame($reviewer->id, $document->reviewed_by);
        $this->assertNotNull($document->reviewed_at);

        $this->assertDatabaseHas('license_applications', [
            'id' => $applicationId,
            'status' => ApplicationStatus::DocumentsRejected->value,
        ]);

        $this->assertSame(1, AuditLog::query()->where('action', 'document.rejected')->where('entity_id', $documentId)->count());

        $notification = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'document.rejected')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString(DocumentRejectionReason::UnclearDocument->label(), $notification->body);
        $this->assertStringContainsString('الصورة غير واضحة في الزاوية.', $notification->body);
        $this->assertStringNotContainsString('application_documents/', $notification->body);
        $this->assertStringNotContainsString((string) $citizen->national_id, $notification->body);

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/approve")->assertStatus(422);
        $this->assertSame(1, Notification::query()->where('user_id', $citizen->id)->where('type', 'document.rejected')->count());
    }

    public function test_reject_other_with_details_and_legacy_admin_compatibility(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);
        $documentId = $this->firstPendingDocumentId($applicationId);

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => DocumentRejectionReason::Other->value,
            'rejection_details' => 'تفاصيل مخصصة من المراجع.',
        ])->assertOk()
            ->assertJsonPath('data.rejection.code', 'other')
            ->assertJsonPath('data.rejection.details', 'تفاصيل مخصصة من المراجع.')
            ->assertJsonPath('data.rejection_reason', 'تفاصيل مخصصة من المراجع.');

        $citizen2 = $this->readyCitizen(['email' => 'legacy-citizen@example.com']);
        $applicationId2 = $this->createSubmittedApplication($citizen2);
        $legacyDocId = $this->firstPendingDocumentId($applicationId2);

        Sanctum::actingAs(User::factory()->dashboardEmployee('employee')->create());
        $this->postJson("/api/admin/documents/{$legacyDocId}/reject", [
            'rejection_reason' => 'Illegible scan.',
        ])->assertOk()
            ->assertJsonPath('data.rejection.code', 'other')
            ->assertJsonPath('data.rejection.details', 'Illegible scan.')
            ->assertJsonPath('data.rejection_reason', 'Illegible scan.');
    }

    public function test_superseded_document_cannot_be_reviewed_and_reupload_loop_works(): void
    {
        $citizen = $this->readyCitizen();
        $applicationId = $this->createSubmittedApplication($citizen);
        $oldDocumentId = $this->firstPendingDocumentId($applicationId);
        $requiredDocumentId = (int) ApplicationDocument::query()->findOrFail($oldDocumentId)->required_document_id;

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/dashboard/document-reviews/documents/{$oldDocumentId}/reject", [
            'rejection_reason_code' => DocumentRejectionReason::WrongDocument->value,
        ])->assertOk();

        Sanctum::actingAs($citizen);
        $checklist = $this->getJson("/api/applications/{$applicationId}/required-documents")->assertOk()->json('data');
        $rejectedItem = collect($checklist)->firstWhere('id', $requiredDocumentId);
        $this->assertSame(DocumentStatus::Rejected->value, $rejectedItem['latest_document']['status']);
        $this->assertSame(DocumentRejectionReason::WrongDocument->value, $rejectedItem['latest_document']['rejection']['code']);

        $this->post(
            "/api/applications/{$applicationId}/documents",
            [
                'required_document_id' => $requiredDocumentId,
                'file' => UploadedFile::fake()->create('replacement.pdf', 90, 'application/pdf'),
            ],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->assertSoftDeleted('application_documents', ['id' => $oldDocumentId]);

        $newDocumentId = (int) ApplicationDocument::query()
            ->where('application_id', $applicationId)
            ->where('required_document_id', $requiredDocumentId)
            ->orderByDesc('id')
            ->value('id');
        $this->assertNotSame($oldDocumentId, $newDocumentId);

        $this->postJson("/api/applications/{$applicationId}/submit-documents")
            ->assertOk()
            ->assertJsonPath('data.status', ApplicationStatus::DocumentsUnderReview->value);

        Sanctum::actingAs($this->reviewer());
        $queueIds = collect($this->getJson('/api/dashboard/document-reviews')->json('data.items'))->pluck('id');
        $this->assertTrue($queueIds->contains($applicationId));

        $details = $this->getJson("/api/dashboard/document-reviews/{$applicationId}")->assertOk()->json('data');
        $active = collect($details['documents'])->firstWhere('required_document.id', $requiredDocumentId);
        $this->assertSame($newDocumentId, $active['latest_document']['id']);

        $this->postJson("/api/dashboard/document-reviews/documents/{$oldDocumentId}/approve")->assertStatus(404);

        $this->postJson("/api/dashboard/document-reviews/documents/{$newDocumentId}/approve")->assertOk();
    }

    public function test_super_admin_can_access_through_permissions(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin('super_admin')->create());
        $this->getJson('/api/dashboard/document-reviews')->assertOk();
        $this->getJson('/api/dashboard/document-reviews/stats')->assertOk();
    }
}
