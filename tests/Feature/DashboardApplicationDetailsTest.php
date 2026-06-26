<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApplicationDetailsTest extends TestCase
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
        ]);
    }

    public function test_authorized_employee_can_view_application_details()
    {
        $citizen = User::factory()->withApprovedProfile()->create();

        $licenseType = LicenseType::query()->where('code', 'private')->first();
        $serviceType = ServiceType::query()->where('code', 'new_license')->first();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-TEST-1',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::DocumentsUnderReview,
        ]);

        $admin = User::factory()->dashboardAdmin('admin')->create();

        // create an audit log for this application
        AuditLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'application.status_changed',
            'entity_type' => 'license_application',
            'entity_id' => $application->id,
            'old_values' => ['status' => 'documents_under_review'],
            'new_values' => ['status' => 'documents_rejected'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        Sanctum::actingAs($admin);

        $resp = $this->getJson("/api/dashboard/applications/{$application->application_number}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id)
            ->assertJsonPath('data.application_number', $application->application_number)
            ->assertJsonPath('data.extra_details.rejection_reason', null)
            ->assertJsonPath('data.audit_logs.0.changes.0.field', 'status')
            ->assertJsonPath('data.audit_logs.0.changes.0.old_label', 'مراجعة الوثائق')
            ->assertJsonPath('data.audit_logs.0.changes.0.new_label', 'رفض الوثائق');

        // Ensure we don't return full citizen/license_type/service_type objects
        $this->assertArrayNotHasKey('citizen', $resp->json('data'));
        $this->assertArrayNotHasKey('license_type', $resp->json('data'));
        $this->assertArrayNotHasKey('service_type', $resp->json('data'));
    }

    public function test_unauthorized_user_cannot_view_application_details()
    {
        $citizen = User::factory()->withApprovedProfile()->create();

        $licenseType = LicenseType::query()->where('code', 'private')->first();
        $serviceType = ServiceType::query()->where('code', 'new_license')->first();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-TEST-2',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
        ]);

        Sanctum::actingAs($citizen);

        $this->getJson("/api/dashboard/applications/{$application->application_number}")
            ->assertStatus(403);
    }
}
