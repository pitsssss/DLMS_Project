<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function makeLicenseAndService(): array
    {
        $licenseType = LicenseType::query()->create([
            'name' => 'Test Private',
            'code' => 'test_private_'.uniqid(),
            'minimum_age' => 18,
            'validity_years' => 5,
            'is_active' => true,
        ]);

        $serviceType = ServiceType::query()->create([
            'name' => 'Test New',
            'code' => 'test_new_'.uniqid(),
            'description' => null,
            'is_active' => true,
        ]);

        return [$licenseType, $serviceType];
    }

    private function readyCitizen(): User
    {
        return User::factory()->create([
            'profile_completed' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_employee_cannot_access_applications_routes(): void
    {
        [$licenseType, $serviceType] = $this->makeLicenseAndService();
        $employeeRole = Role::query()->where('name', 'employee')->firstOrFail();
        $employee = User::factory()->create(['role_id' => $employeeRole->id]);

        Sanctum::actingAs($employee);

        $this->getJson('/api/applications')->assertForbidden();
        $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->assertForbidden();
        $this->getJson('/api/applications/1')->assertForbidden();
    }

    public function test_citizen_cannot_create_application_without_completed_profile(): void
    {
        [$licenseType, $serviceType] = $this->makeLicenseAndService();
        $citizen = User::factory()->create([
            'profile_completed' => false,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_citizen_can_create_list_and_show_draft_application(): void
    {
        [$licenseType, $serviceType] = $this->makeLicenseAndService();
        $citizen = $this->readyCitizen();

        Sanctum::actingAs($citizen);

        $create = $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ]);

        $create->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', ApplicationStatus::Draft->value)
            ->assertJsonPath('data.license_type.id', $licenseType->id)
            ->assertJsonPath('data.service_type.id', $serviceType->id);

        $applicationId = $create->json('data.id');
        $this->assertNotNull($applicationId);
        $this->assertDatabaseHas('license_applications', [
            'id' => $applicationId,
            'citizen_id' => $citizen->id,
            'status' => ApplicationStatus::Draft->value,
        ]);
        $this->assertDatabaseHas('application_status_histories', [
            'application_id' => $applicationId,
            'new_status' => ApplicationStatus::Draft->value,
        ]);

        $this->getJson('/api/applications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $applicationId);

        $this->getJson("/api/applications/{$applicationId}")
            ->assertOk()
            ->assertJsonPath('data.application_number', $create->json('data.application_number'));
    }

    public function test_citizen_cannot_view_another_citizens_application(): void
    {
        [$licenseType, $serviceType] = $this->makeLicenseAndService();
        $owner = $this->readyCitizen();
        $other = $this->readyCitizen();

        Sanctum::actingAs($owner);
        $applicationId = $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->json('data.id');

        Sanctum::actingAs($other);
        $this->getJson("/api/applications/{$applicationId}")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_public_license_types_and_service_types_endpoints(): void
    {
        [$licenseType, $serviceType] = $this->makeLicenseAndService();

        $this->getJson('/api/license-types')
            ->assertOk()
            ->assertJsonFragment(['id' => $licenseType->id]);

        $this->getJson('/api/service-types')
            ->assertOk()
            ->assertJsonFragment(['id' => $serviceType->id]);
    }

    public function test_inactive_license_type_cannot_be_used_for_new_application(): void
    {
        [$licenseType, $serviceType] = $this->makeLicenseAndService();
        $licenseType->update(['is_active' => false]);

        Sanctum::actingAs($this->readyCitizen());

        $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->assertStatus(422);
    }

    public function test_ping_reports_phase_five(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJsonPath('data.phase', 5);
    }
}
