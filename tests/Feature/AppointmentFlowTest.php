<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\TestResultStatus;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentFlowTest extends TestCase
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
            AppointmentSlotsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function citizenInAppointmentPending(): array
    {
        $citizen = User::factory()->create([
            'profile_completed' => true,
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-APT-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::AppointmentPending,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ]);

        return [$citizen, $application];
    }

    private function employeeUser(): User
    {
        $role = Role::query()->where('name', 'employee')->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function visionSlot(): AppointmentSlot
    {
        $vision = TestType::query()->where('code', 'vision')->firstOrFail();

        return AppointmentSlot::query()
            ->where('test_type_id', $vision->id)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->firstOrFail();
    }

    public function test_ping_reports_phase_six(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJsonPath('data.phase', 9);
    }

    public function test_citizen_can_list_available_tests_and_book_vision_appointment(): void
    {
        [$citizen, $application] = $this->citizenInAppointmentPending();
        Sanctum::actingAs($citizen);

        $this->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk()
            ->assertJsonPath('data.tests.0.code', 'vision')
            ->assertJsonPath('data.tests.0.can_book', true);

        $slot = $this->visionSlot();

        $book = $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ]);

        $book->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::Booked->value)
            ->assertJsonPath('data.test_type.code', 'vision');

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::InTesting->value,
        ]);

        $slot->refresh();
        $this->assertEquals(1, $slot->booked_count);
    }

    public function test_employee_can_record_passed_result_and_unlock_next_test(): void
    {
        [$citizen, $application] = $this->citizenInAppointmentPending();
        Sanctum::actingAs($citizen);

        $slot = $this->visionSlot();
        $appointmentId = (int) $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ])->json('data.id');

        $employee = $this->employeeUser();
        Sanctum::actingAs($employee);

        $this->postJson("/api/admin/test-appointments/{$appointmentId}/record-result", [
            'result' => TestResultStatus::Passed->value,
        ])->assertOk()
            ->assertJsonPath('data.result', TestResultStatus::Passed->value);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::InTesting->value,
        ]);

        Sanctum::actingAs($citizen);

        $this->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk()
            ->assertJsonPath('data.tests.0.passed', true)
            ->assertJsonPath('data.tests.1.code', 'theory')
            ->assertJsonPath('data.tests.1.can_book', true);
    }

    public function test_failed_test_moves_application_to_waiting_retest(): void
    {
        [$citizen, $application] = $this->citizenInAppointmentPending();
        Sanctum::actingAs($citizen);

        $appointmentId = (int) $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $this->visionSlot()->id,
        ])->json('data.id');

        Sanctum::actingAs($this->employeeUser());

        $this->postJson("/api/admin/test-appointments/{$appointmentId}/record-result", [
            'result' => TestResultStatus::Failed->value,
        ])->assertOk();

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::WaitingRetest->value,
        ]);
    }

    public function test_citizen_can_cancel_appointment_and_release_slot(): void
    {
        [$citizen, $application] = $this->citizenInAppointmentPending();
        Sanctum::actingAs($citizen);

        $slot = $this->visionSlot();
        $appointmentId = (int) $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ])->json('data.id');

        $this->deleteJson("/api/appointments/{$appointmentId}/cancel", [
            'cancellation_reason' => 'Schedule conflict',
        ])->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::Cancelled->value);

        $slot->refresh();
        $this->assertEquals(0, $slot->booked_count);
    }

    public function test_cannot_book_when_slot_is_full(): void
    {
        [$citizenA, $applicationA] = $this->citizenInAppointmentPending();
        [$citizenB, $applicationB] = $this->citizenInAppointmentPending();

        $slot = AppointmentSlot::query()->where('test_type_id', TestType::query()->where('code', 'vision')->value('id'))->firstOrFail();
        $slot->update(['capacity' => 1, 'booked_count' => 0]);

        Sanctum::actingAs($citizenA);
        $this->postJson("/api/applications/{$applicationA->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ])->assertOk();

        Sanctum::actingAs($citizenB);
        $this->postJson("/api/applications/{$applicationB->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ])->assertStatus(422);
    }
}
