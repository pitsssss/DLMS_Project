<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Models\AppointmentCenter;
use App\Models\AppointmentSlot;
use App\Models\AuditLog;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use App\Support\BusinessClock;
use Database\Seeders\AppointmentCentersSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentSlotConcurrencyTest extends TestCase
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
            AppointmentCentersSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function clock(): BusinessClock
    {
        return app(BusinessClock::class);
    }

    private function visionType(): TestType
    {
        return TestType::query()->where('code', 'vision')->firstOrFail();
    }

    private function center(): AppointmentCenter
    {
        return AppointmentCenter::query()->firstOrFail();
    }

    private function makeSlot(int $capacity = 1): AppointmentSlot
    {
        return AppointmentSlot::query()->create([
            'test_type_id' => $this->visionType()->id,
            'appointment_center_id' => $this->center()->id,
            'date' => $this->clock()->now()->addDays(3)->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'capacity' => $capacity,
            'booked_count' => 0,
            'location' => 'concurrency',
            'is_active' => true,
            'version' => 1,
        ]);
    }

    private function citizenWithApplication(string $suffix): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'email' => "citizen-{$suffix}@example.com",
        ]);

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-CONC-'.strtoupper($suffix),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::AppointmentPending,
            'current_test_type_id' => null,
            'submitted_at' => now(),
        ]);

        return [$citizen, $application];
    }

    public function test_concurrent_booking_cannot_overbook_single_capacity_slot(): void
    {
        $slot = $this->makeSlot(1);
        [$citizenA, $applicationA] = $this->citizenWithApplication('A');
        [$citizenB, $applicationB] = $this->citizenWithApplication('B');

        $success = 0;
        $failure = 0;

        foreach ([[$citizenA, $applicationA], [$citizenB, $applicationB]] as [$citizen, $application]) {
            Sanctum::actingAs($citizen);
            $response = $this->postJson("/api/applications/{$application->id}/appointments", [
                'appointment_slot_id' => $slot->id,
            ]);

            if ($response->status() === 200) {
                $success++;
            } else {
                $failure++;
            }
        }

        $slot->refresh();
        $this->assertSame(1, $success);
        $this->assertSame(1, $failure);
        $this->assertSame(1, $slot->booked_count);
    }

    public function test_cancel_releases_capacity_and_is_idempotent_on_status(): void
    {
        $slot = $this->makeSlot(2);
        [$citizen, $application] = $this->citizenWithApplication('C');
        Sanctum::actingAs($citizen);

        $appointmentId = (int) $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ])->json('data.id');

        $slot->refresh();
        $this->assertSame(1, $slot->booked_count);

        $this->deleteJson("/api/appointments/{$appointmentId}/cancel", [
            'reason' => 'تغيير خطط',
        ])->assertOk();

        $slot->refresh();
        $this->assertSame(0, $slot->booked_count);

        $this->deleteJson("/api/appointments/{$appointmentId}/cancel")
            ->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.cancelled',
            'entity_type' => 'appointment',
            'entity_id' => $appointmentId,
        ]);
    }

    public function test_reschedule_releases_and_consumes_capacity_with_audit(): void
    {
        $slotA = $this->makeSlot(2);
        $slotB = AppointmentSlot::query()->create([
            'test_type_id' => $this->visionType()->id,
            'appointment_center_id' => $this->center()->id,
            'date' => $this->clock()->now()->addDays(4)->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'capacity' => 2,
            'booked_count' => 0,
            'location' => 'slot-b',
            'is_active' => true,
            'version' => 1,
        ]);

        [$citizen, $application] = $this->citizenWithApplication('D');
        Sanctum::actingAs($citizen);

        $appointmentId = (int) $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $slotA->id,
        ])->json('data.id');

        $slotA->refresh();
        $this->assertSame(1, $slotA->booked_count);

        $this->putJson("/api/appointments/{$appointmentId}/reschedule", [
            'appointment_slot_id' => $slotB->id,
        ])->assertOk();

        $slotA->refresh();
        $slotB->refresh();
        $this->assertSame(0, $slotA->booked_count);
        $this->assertSame(1, $slotB->booked_count);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.rescheduled',
            'entity_type' => 'appointment',
            'entity_id' => $appointmentId,
        ]);
    }

    public function test_reschedule_lock_order_preserves_booked_counts(): void
    {
        $slotLow = $this->makeSlot(5);
        $slotHigh = AppointmentSlot::query()->create([
            'test_type_id' => $this->visionType()->id,
            'appointment_center_id' => $this->center()->id,
            'date' => $this->clock()->now()->addDays(5)->toDateString(),
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'capacity' => 5,
            'booked_count' => 0,
            'location' => 'high',
            'is_active' => true,
            'version' => 1,
        ]);

        if ($slotLow->id > $slotHigh->id) {
            [$slotLow, $slotHigh] = [$slotHigh, $slotLow];
        }

        [$citizenA, $applicationA] = $this->citizenWithApplication('E');
        [$citizenB, $applicationB] = $this->citizenWithApplication('F');

        Sanctum::actingAs($citizenA);
        $appointmentA = (int) $this->postJson("/api/applications/{$applicationA->id}/appointments", [
            'appointment_slot_id' => $slotLow->id,
        ])->json('data.id');

        Sanctum::actingAs($citizenB);
        $appointmentB = (int) $this->postJson("/api/applications/{$applicationB->id}/appointments", [
            'appointment_slot_id' => $slotHigh->id,
        ])->json('data.id');

        Sanctum::actingAs($citizenA);
        $this->putJson("/api/appointments/{$appointmentA}/reschedule", [
            'appointment_slot_id' => $slotHigh->id,
        ])->assertOk();

        Sanctum::actingAs($citizenB);
        $this->putJson("/api/appointments/{$appointmentB}/reschedule", [
            'appointment_slot_id' => $slotLow->id,
        ])->assertOk();

        $slotLow->refresh();
        $slotHigh->refresh();

        $this->assertSame(1, $slotLow->booked_count);
        $this->assertSame(1, $slotHigh->booked_count);
        $this->assertGreaterThanOrEqual(0, $slotLow->booked_count);
        $this->assertLessThanOrEqual($slotLow->capacity, $slotLow->booked_count);
        $this->assertLessThanOrEqual($slotHigh->capacity, $slotHigh->booked_count);
    }

    public function test_concurrent_slot_update_cannot_reduce_capacity_below_booked_count(): void
    {
        $employee = User::factory()->dashboardEmployee('test_employee')->create();
        Sanctum::actingAs($employee);

        $slot = $this->makeSlot(5);
        $slot->update(['booked_count' => 3, 'version' => 1]);

        $this->patchJson("/api/dashboard/appointment-slots/{$slot->id}", [
            'version' => 1,
            'capacity' => 2,
        ])->assertStatus(422);

        $slot->refresh();
        $this->assertSame(5, $slot->capacity);
        $this->assertSame(3, $slot->booked_count);
    }
}
