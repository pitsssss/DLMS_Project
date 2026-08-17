<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\ServiceType;
use App\Models\User;
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
use Tests\Support\FakeDocumentFile;
use Tests\TestCase;

class LicenseUnblockFlowTest extends TestCase
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

    private function readyCitizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function licenseEmployee(): User
    {
        return User::factory()->dashboardEmployee('license_employee')->create();
    }

    private function unauthorizedEmployee(): User
    {
        return User::factory()->dashboardEmployee('fines_employee')->create();
    }

    private function documentReviewer(): User
    {
        return User::factory()->dashboardEmployee('employee')->create();
    }

    /**
     * @return array{0: User, 1: License}
     */
    private function citizenWithBlockedLicense(?User $citizen = null, array $licenseOverrides = []): array
    {
        $citizen = $citizen ?? $this->readyCitizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $originalApplication = LicenseApplication::query()->create([
            'application_number' => 'APP-BLK-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now()->subYear(),
            'approved_at' => now()->subYear(),
            'issued_at' => now()->subYear(),
        ]);

        $license = License::query()->create(array_merge([
            'license_number' => 'LIC-BLK-'.strtoupper(Str::random(6)),
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

    private function createUnblockApplication(User $citizen, License $license): LicenseApplication
    {
        Sanctum::actingAs($citizen);

        $response = $this->postJson('/api/applications', [
            'service_type_code' => 'license_unblock',
            'related_license_id' => $license->id,
        ])->assertOk();

        return LicenseApplication::query()->findOrFail((int) $response->json('data.id'));
    }

    private function uploadAndSubmitDocuments(User $citizen, int $applicationId): void
    {
        Sanctum::actingAs($citizen);

        $checklist = $this->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->json('data');

        foreach ($checklist as $item) {
            $this->post(
                "/api/applications/{$applicationId}/documents",
                [
                    'required_document_id' => (int) $item['id'],
                    'file' => FakeDocumentFile::pdf('doc-'.$item['code'].'.pdf'),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }

        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();
    }

    private function approveAllDocumentsForApplication(int $applicationId): void
    {
        Sanctum::actingAs($this->documentReviewer());

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

    private function prepareApprovedUnblockApplication(?User $citizen = null, ?License $license = null): array
    {
        [$citizen, $license] = $citizen && $license ? [$citizen, $license] : $this->citizenWithBlockedLicense($citizen);
        $application = $this->createUnblockApplication($citizen, $license);
        $this->uploadAndSubmitDocuments($citizen, $application->id);
        $this->approveAllDocumentsForApplication($application->id);
        $this->completePayment($citizen, $application->id);

        $application->refresh();

        return [$citizen, $license, $application];
    }

    public function test_blocked_owned_license_can_create_unblock_application(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'service_type_code' => 'license_unblock',
            'related_license_id' => $license->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', ApplicationStatus::Draft->value)
            ->assertJsonPath('data.service_type.code', 'license_unblock')
            ->assertJsonPath('data.related_license.id', $license->id);
    }

    public function test_active_license_cannot_create_unblock_application(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $license->update(['status' => LicenseStatus::Active, 'block_reason' => null, 'blocked_at' => null]);

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'service_type_code' => 'license_unblock',
            'related_license_id' => $license->id,
        ])->assertStatus(422);
    }

    public function test_another_citizens_license_is_forbidden(): void
    {
        [, $license] = $this->citizenWithBlockedLicense();
        $other = $this->readyCitizen();

        Sanctum::actingAs($other);

        $this->postJson('/api/applications', [
            'service_type_code' => 'license_unblock',
            'related_license_id' => $license->id,
        ])->assertStatus(403);
    }

    public function test_generic_services_still_reject_blocked_license(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'service_type_code' => 'renew_license',
            'related_license_id' => $license->id,
        ])->assertStatus(422);
    }

    public function test_unpaid_fines_block_unblock_application_creation(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();

        Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => $license->id,
            'amount' => '100.00',
            'currency' => 'USD',
            'reason' => 'Speeding',
            'status' => FineStatus::Unpaid,
            'issued_at' => now(),
        ]);

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'service_type_code' => 'license_unblock',
            'related_license_id' => $license->id,
        ])->assertStatus(422);
    }

    public function test_duplicate_active_unblock_application_is_rejected(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $this->createUnblockApplication($citizen, $license);

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'service_type_code' => 'license_unblock',
            'related_license_id' => $license->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.applications.duplicate_active_application_license'));
    }

    public function test_new_unblock_application_allowed_after_completed_terminal_application(): void
    {
        [$citizen, $license, $application] = $this->prepareApprovedUnblockApplication();

        Sanctum::actingAs($this->licenseEmployee());
        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")->assertOk();

        Sanctum::actingAs($citizen);
        $license->refresh();
        $license->update([
            'status' => LicenseStatus::Blocked,
            'block_reason' => 'Second hold',
            'blocked_at' => now(),
        ]);

        $this->postJson('/api/applications', [
            'service_type_code' => 'license_unblock',
            'related_license_id' => $license->id,
        ])->assertOk();
    }

    public function test_required_documents_for_unblock_are_returned(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $application = $this->createUnblockApplication($citizen, $license);

        Sanctum::actingAs($citizen);

        $codes = collect($this->getJson("/api/applications/{$application->id}/required-documents")->json('data'))
            ->pluck('code')
            ->all();

        $this->assertContains('national_id_copy', $codes);
        $this->assertContains('fine_clearance', $codes);
    }

    public function test_unblock_fee_is_returned_for_application(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $application = $this->createUnblockApplication($citizen, $license);
        $this->uploadAndSubmitDocuments($citizen, $application->id);
        $this->approveAllDocumentsForApplication($application->id);

        Sanctum::actingAs($citizen);

        $this->getJson("/api/applications/{$application->id}/fee")
            ->assertOk()
            ->assertJsonPath('data.fee.code', 'unblock_fee');
    }

    public function test_full_e2e_unblock_application_flow(): void
    {
        [$citizen, $license, $application] = $this->prepareApprovedUnblockApplication();
        $licenseCountBefore = License::query()->count();
        $licenseNumberBefore = $license->license_number;

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")
            ->assertOk()
            ->assertJsonPath('data.license.status', LicenseStatus::Active->value);

        $application->refresh();
        $license->refresh();

        $this->assertSame(ApplicationStatus::Completed->value, $application->status->value);
        $this->assertSame(LicenseStatus::Active, $license->status);
        $this->assertNull($license->block_reason);
        $this->assertSame($licenseNumberBefore, $license->license_number);
        $this->assertSame($licenseCountBefore, License::query()->count());

        $this->assertDatabaseHas('application_status_histories', [
            'application_id' => $application->id,
            'new_status' => ApplicationStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('license_status_histories', [
            'license_id' => $license->id,
            'action' => 'unblocked',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'application.unblock_completed',
            'entity_type' => 'license_application',
            'entity_id' => $application->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $citizen->id,
            'type' => 'license.unblocked',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $citizen->id,
            'type' => 'application.completed',
        ]);
    }

    public function test_unblock_expired_blocked_license_resolves_to_expired(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense(null, [
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        [, , $application] = $this->prepareApprovedUnblockApplication($citizen, $license);

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")
            ->assertOk()
            ->assertJsonPath('data.license.status', LicenseStatus::Expired->value);
    }

    public function test_unauthorized_employee_cannot_unblock_from_application(): void
    {
        [, , $application] = $this->prepareApprovedUnblockApplication();

        Sanctum::actingAs($this->unauthorizedEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")
            ->assertForbidden();
    }

    public function test_cannot_unblock_before_approved_status(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $application = $this->createUnblockApplication($citizen, $license);

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")
            ->assertStatus(422);
    }

    public function test_cannot_unblock_wrong_service_application(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $license->update(['status' => LicenseStatus::Active, 'block_reason' => null, 'blocked_at' => null]);

        $renewable = License::query()->create([
            'license_number' => 'LIC-REN-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $license->license_type_id,
            'application_id' => $license->application_id,
            'status' => LicenseStatus::Expired,
            'issue_date' => now()->subYears(3)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        Sanctum::actingAs($citizen);
        $renewAppId = (int) $this->postJson('/api/applications', [
            'service_type_code' => 'renew_license',
            'related_license_id' => $renewable->id,
        ])->json('data.id');

        $renewApp = LicenseApplication::query()->findOrFail($renewAppId);
        $renewApp->update(['status' => ApplicationStatus::Approved]);

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$renewApp->id}/unblock-license")
            ->assertStatus(422);
    }

    public function test_cannot_execute_unblock_twice_on_same_application(): void
    {
        [, , $application] = $this->prepareApprovedUnblockApplication();

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")->assertOk();
        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")->assertStatus(422);
    }

    public function test_stale_license_state_is_rejected_at_final_action(): void
    {
        [$citizen, $license, $application] = $this->prepareApprovedUnblockApplication();
        $license->update(['status' => LicenseStatus::Active, 'block_reason' => null, 'blocked_at' => null]);

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")
            ->assertStatus(422);

        $this->assertSame(ApplicationStatus::Approved->value, $application->fresh()->status->value);
    }

    public function test_unpaid_fines_block_final_unblock_action(): void
    {
        [$citizen, $license, $application] = $this->prepareApprovedUnblockApplication();

        Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => $license->id,
            'amount' => '50.00',
            'currency' => 'USD',
            'reason' => 'Late fee',
            'status' => FineStatus::Unpaid,
            'issued_at' => now(),
        ]);

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/unblock-license")
            ->assertStatus(422);

        $this->assertSame(LicenseStatus::Blocked, $license->fresh()->status);
        $this->assertSame(ApplicationStatus::Approved->value, $application->fresh()->status->value);
    }

    public function test_employee_can_reject_approved_unblock_application(): void
    {
        [$citizen, $license, $application] = $this->prepareApprovedUnblockApplication();

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/reject", [
            'reason' => 'Insufficient supporting evidence',
        ])
            ->assertOk();

        $application->refresh();
        $license->refresh();

        $this->assertSame(ApplicationStatus::Rejected, $application->status);
        $this->assertSame('Insufficient supporting evidence', $application->rejection_reason);
        $this->assertSame(LicenseStatus::Blocked, $license->status);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $citizen->id,
            'type' => 'application.rejected',
        ]);
    }

    public function test_rejection_requires_reason(): void
    {
        [, , $application] = $this->prepareApprovedUnblockApplication();

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/dashboard/applications/{$application->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_can_request_unblock_flag_on_licenses(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();

        Sanctum::actingAs($citizen);

        $this->getJson('/api/licenses')
            ->assertOk()
            ->assertJsonPath('data.0.can_request_unblock', true);

        $this->getJson("/api/licenses/{$license->id}")
            ->assertOk()
            ->assertJsonPath('data.can_request_unblock', true);
    }

    public function test_issue_license_still_rejects_license_unblock_application(): void
    {
        [, , $application] = $this->prepareApprovedUnblockApplication();

        Sanctum::actingAs($this->licenseEmployee());

        $this->postJson("/api/admin/applications/{$application->id}/issue-license")
            ->assertStatus(422);
    }

    public function test_dashboard_application_queue_filters_unblock_applications(): void
    {
        [, , $application] = $this->prepareApprovedUnblockApplication();

        Sanctum::actingAs($this->licenseEmployee());

        $this->getJson('/api/dashboard/applications?service_type_code=license_unblock&status=approved')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $application->id);
    }

    public function test_unblock_request_endpoint_does_not_create_application(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $before = LicenseApplication::query()->count();

        Sanctum::actingAs($citizen);

        $this->postJson("/api/licenses/{$license->id}/unblock-request")->assertOk();

        $this->assertSame($before, LicenseApplication::query()->count());
        $this->assertSame(LicenseStatus::Blocked, $license->fresh()->status);
    }

    public function test_application_creation_does_not_emit_license_unblocked_notification(): void
    {
        [$citizen, $license] = $this->citizenWithBlockedLicense();
        $this->createUnblockApplication($citizen, $license);

        $this->assertSame(0, Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'license.unblocked')
            ->count());
    }
}
