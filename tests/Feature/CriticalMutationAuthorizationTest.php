<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\TestResultStatus;
use App\Models\ApplicationDocument;
use App\Models\AppointmentSlot;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\RequiredDocument;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use Database\Seeders\AppointmentCentersSeeder;
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
use Tests\TestCase;

/**
 * Same-route mutation 401/403 evidence for critical admin operations.
 */
class CriticalMutationAuthorizationTest extends TestCase
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
            RequiredDocumentsSeeder::class,
            AppointmentCentersSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_document_approve_and_reject_require_auth_and_permission(): void
    {
        $documentId = $this->pendingDocumentId();

        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/approve")
            ->assertUnauthorized();
        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => 'unclear_document',
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->dashboardEmployee('fines_employee')->create());
        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/approve")
            ->assertForbidden();
        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => 'unclear_document',
        ])->assertForbidden();
    }

    public function test_license_block_and_unblock_require_auth_and_permission(): void
    {
        $licenseId = $this->activeLicenseId();

        $this->postJson("/api/dashboard/licenses/{$licenseId}/block", [
            'reason' => 'تحقيق',
        ])->assertUnauthorized();
        $this->postJson("/api/dashboard/licenses/{$licenseId}/unblock")
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->dashboardEmployee('fines_employee')->create());
        $this->postJson("/api/dashboard/licenses/{$licenseId}/block", [
            'reason' => 'تحقيق',
        ])->assertForbidden();
        $this->postJson("/api/dashboard/licenses/{$licenseId}/unblock")
            ->assertForbidden();
    }

    public function test_citizen_activate_and_deactivate_require_auth_and_permission(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", [
            'reason' => 'سبب',
        ])->assertUnauthorized();
        $this->postJson("/api/dashboard/citizens/{$citizen->id}/activate")
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->dashboardEmployee('fines_employee')->create());
        $this->postJson("/api/dashboard/citizens/{$citizen->id}/deactivate", [
            'reason' => 'سبب',
        ])->assertForbidden();
        $this->postJson("/api/dashboard/citizens/{$citizen->id}/activate")
            ->assertForbidden();
    }

    public function test_role_mutations_require_auth_and_super_admin(): void
    {
        $role = Role::query()->create([
            'name' => 'crit_mut_role_'.Str::lower(Str::random(4)),
            'display_name' => 'دور اختبار',
            'is_system' => false,
            'is_assignable' => true,
            'is_archived' => false,
            'is_protected' => false,
            'version' => 1,
        ]);

        $this->postJson('/api/dashboard/access-control/roles', [
            'name' => 'crit_new_role',
            'display_name' => 'جديد',
        ])->assertUnauthorized();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}", [
            'display_name' => 'محدث',
            'version' => 1,
        ])->assertUnauthorized();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/archive", [
            'reason' => 'أرشفة',
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->dashboardEmployee('application_manager')->create());
        // Non-super-admin with a normal dashboard role must not mutate roles.
        $this->postJson('/api/dashboard/access-control/roles', [
            'name' => 'crit_new_role2',
            'display_name' => 'جديد',
        ])->assertForbidden();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}", [
            'display_name' => 'محدث',
            'version' => 1,
        ])->assertForbidden();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/archive", [
            'reason' => 'أرشفة',
        ])->assertForbidden();

        $this->patchJson("/api/dashboard/access-control/roles/{$role->id}/restore")
            ->assertForbidden();
    }

    public function test_record_test_result_requires_permission(): void
    {
        $appointmentId = $this->bookedAppointmentId();

        Sanctum::actingAs(User::factory()->dashboardEmployee('fines_employee')->create());
        $this->postJson("/api/admin/test-appointments/{$appointmentId}/record-result", [
            'result' => TestResultStatus::Passed->value,
        ])->assertForbidden();
    }

    public function test_fine_create_and_update_require_auth_and_permission(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 1000,
            'reason' => 'اختبار',
            'status' => FineStatus::Unpaid,
        ]);

        $this->postJson('/api/admin/fines', [
            'citizen_id' => $citizen->id,
            'amount' => 500,
            'reason' => 'غرامة',
        ])->assertUnauthorized();

        $this->putJson("/api/admin/fines/{$fine->id}", [
            'status' => FineStatus::Paid->value,
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->dashboardEmployee('license_employee')->create());
        $this->postJson('/api/admin/fines', [
            'citizen_id' => $citizen->id,
            'amount' => 500,
            'reason' => 'غرامة',
        ])->assertForbidden();

        $this->putJson("/api/admin/fines/{$fine->id}", [
            'status' => FineStatus::Paid->value,
        ])->assertForbidden();
    }

    private function bookedAppointmentId(): int
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $vision = TestType::query()->where('code', 'vision')->firstOrFail();
        $centerId = (int) \App\Models\AppointmentCenter::query()->value('id');

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-CMR-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::InTesting,
            'current_test_type_id' => $vision->id,
            'submitted_at' => now(),
        ]);

        $slot = AppointmentSlot::query()->create([
            'test_type_id' => $vision->id,
            'appointment_center_id' => $centerId,
            'date' => app(\App\Support\BusinessClock::class)->now()->addDays(3)->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'capacity' => 5,
            'booked_count' => 1,
            'location' => 'cm-auth',
            'is_active' => true,
            'version' => 1,
        ]);

        return (int) TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $vision->id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => now()->addDays(3),
        ])->id;
    }

    private function pendingDocumentId(): int
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-CM-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::DocumentsUnderReview,
            'submitted_at' => now(),
        ]);

        $required = RequiredDocument::query()->where('is_active', true)->firstOrFail();

        return (int) ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'required_document_id' => $required->id,
            'file_path' => 'application_documents/cm-auth.pdf',
            'original_name' => 'cm.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => DocumentStatus::PendingReview,
        ])->id;
    }

    private function activeLicenseId(): int
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseTypeId = (int) LicenseType::query()->where('code', 'private')->value('id');
        $serviceTypeId = (int) ServiceType::query()->where('code', 'new_license')->value('id');

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-CML-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now(),
            'issued_at' => now(),
        ]);

        return (int) License::query()->create([
            'license_number' => 'LIC-CM-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(10)->toDateString(),
            'verification_token' => Str::random(48),
        ])->id;
    }
}
