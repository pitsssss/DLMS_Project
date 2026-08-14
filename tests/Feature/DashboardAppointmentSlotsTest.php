<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\UserType;
use App\Models\AppointmentCenter;
use App\Models\AppointmentSlot;
use App\Models\AuditLog;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use App\Support\BusinessClock;
use Database\Seeders\AppointmentCentersSeeder;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardAppointmentSlotsTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->seed([
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            TestTypesSeeder::class,
            FeesSeeder::class,
            AppointmentCentersSeeder::class,
            AppointmentSlotsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function asTestEmployee(): User
    {
        $employee = User::factory()->dashboardEmployee('test_employee')->create();
        Sanctum::actingAs($employee);

        return $employee;
    }

    private function asSuperAdmin(): User
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function asViewOnlyAppointments(): User
    {
        $role = Role::query()->create([
            'name' => 'appointments_viewer_'.Str::random(4),
            'display_name' => 'Appointments Viewer',
        ]);
        $ids = Permission::query()
            ->whereIn('name', ['access_dashboard', 'view_appointments'])
            ->pluck('id');
        $role->permissions()->sync($ids);

        $user = User::factory()->create([
            'user_type' => UserType::Employee,
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed' => true,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function clock(): BusinessClock
    {
        return app(BusinessClock::class);
    }

    private function futureDate(int $days = 3): string
    {
        return $this->clock()->now()->addDays($days)->toDateString();
    }

    private function visionType(): TestType
    {
        return TestType::query()->where('code', 'vision')->firstOrFail();
    }

    private function center(): AppointmentCenter
    {
        return AppointmentCenter::query()->where('name', 'المركز الرئيسي')->firstOrFail();
    }

    private function makeSlot(array $overrides = []): AppointmentSlot
    {
        $vision = $this->visionType();
        $center = $this->center();

        return AppointmentSlot::query()->create(array_merge([
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => $this->futureDate(5),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 5,
            'booked_count' => 0,
            'location' => $center->name,
            'is_active' => true,
            'version' => 1,
        ], $overrides));
    }

    public function test_unauthenticated_receives_401(): void
    {
        $this->getJson('/api/dashboard/appointment-slots')->assertUnauthorized();
    }

    public function test_citizen_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['user_type' => UserType::Citizen]));

        $this->getJson('/api/dashboard/appointment-slots')->assertForbidden();
    }

    public function test_dashboard_user_without_permission_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('payment_employee')->create());

        $this->getJson('/api/dashboard/appointment-slots')->assertForbidden();
        $this->postJson('/api/dashboard/appointment-slots', [])->assertForbidden();
    }

    public function test_view_appointments_can_list_details_and_bookings(): void
    {
        $this->asViewOnlyAppointments();
        $slot = $this->makeSlot();

        $this->getJson('/api/dashboard/appointment-slots')->assertOk();
        $this->getJson("/api/dashboard/appointment-slots/{$slot->id}")->assertOk();
        $this->getJson("/api/dashboard/appointment-slots/{$slot->id}/bookings")->assertOk();
        $this->postJson('/api/dashboard/appointment-slots', [])->assertForbidden();
    }

    public function test_manage_appointments_can_mutate(): void
    {
        $this->asTestEmployee();
        $vision = $this->visionType();
        $center = $this->center();

        $created = $this->postJson('/api/dashboard/appointment-slots', [
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => $this->futureDate(7),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'capacity' => 4,
            'location' => 'قاعة 1',
        ])->assertCreated()->json('data');

        $this->assertSame(0, $created['booked_count']);
        $this->assertSame('متاح', $created['availability_status_label']);
        $this->assertStringNotContainsString('messages.', $created['availability_status_label']);
    }

    public function test_list_supports_pagination_and_filters(): void
    {
        $this->asTestEmployee();
        $slot = $this->makeSlot(['capacity' => 2, 'booked_count' => 2]);

        $response = $this->getJson('/api/dashboard/appointment-slots?per_page=10&availability=full&test_type_code=vision')
            ->assertOk();

        $items = collect($response->json('data.items'));
        $this->assertTrue($items->contains(fn ($row) => $row['id'] === $slot->id));
        $this->assertArrayHasKey('pagination', $response->json('data'));
        $this->assertLessThanOrEqual(10, count($response->json('data.items')));
    }

    public function test_date_and_center_filters(): void
    {
        $this->asTestEmployee();
        $slot = $this->makeSlot(['date' => $this->futureDate(8)]);
        $center = $this->center();

        $this->getJson('/api/dashboard/appointment-slots?date_from='.$slot->date->format('Y-m-d').'&date_to='.$slot->date->format('Y-m-d').'&appointment_center_id='.$center->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $slot->id]);
    }

    public function test_options_return_arabic_labels(): void
    {
        $this->asTestEmployee();

        $data = $this->getJson('/api/dashboard/appointment-slots/options')->assertOk()->json('data');
        $this->assertNotEmpty($data['test_types']);
        $this->assertNotEmpty($data['appointment_centers']);
        $this->assertSame('متاح', collect($data['availability_states'])->firstWhere('value', 'available')['label']);
        foreach ($data['availability_states'] as $option) {
            $this->assertStringNotContainsString('messages.', $option['label']);
        }
    }

    public function test_stats_correctness_and_zero_capacity_utilization_safety(): void
    {
        $this->asTestEmployee();
        $this->makeSlot(['capacity' => 10, 'booked_count' => 4, 'date' => $this->clock()->now()->toDateString()]);

        $stats = $this->getJson('/api/dashboard/appointment-slots/stats')->assertOk()->json('data');
        $this->assertArrayHasKey('total_slots', $stats);
        $this->assertArrayHasKey('utilization_rate', $stats);
        $this->assertArrayHasKey('slots_today', $stats);
        $this->assertGreaterThan(0, $stats['total_slots']);
        $this->assertGreaterThanOrEqual(0, $stats['remaining_capacity']);
    }

    public function test_create_rejects_past_date_and_invalid_times_and_capacity(): void
    {
        $this->asTestEmployee();
        $vision = $this->visionType();
        $center = $this->center();
        $past = $this->clock()->now()->subDay()->toDateString();

        $this->postJson('/api/dashboard/appointment-slots', [
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => $past,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'capacity' => 3,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.appointment_slots.past_date_rejected'));

        $this->postJson('/api/dashboard/appointment-slots', [
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => $this->futureDate(2),
            'start_time' => '10:00',
            'end_time' => '09:00',
            'capacity' => 3,
        ])->assertStatus(422);

        $this->postJson('/api/dashboard/appointment-slots', [
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => $this->futureDate(2),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'capacity' => 0,
        ])->assertStatus(422);
    }

    public function test_create_rejects_duplicate_and_ignores_client_booked_count(): void
    {
        $this->asTestEmployee();
        $vision = $this->visionType();
        $center = $this->center();
        $date = $this->futureDate(9);

        $payload = [
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => $date,
            'start_time' => '12:00',
            'end_time' => '13:00',
            'capacity' => 3,
            'booked_count' => 99,
            'created_by' => 999,
        ];

        $created = $this->postJson('/api/dashboard/appointment-slots', $payload)
            ->assertCreated()
            ->json('data');

        $this->assertSame(0, $created['booked_count']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment_slot.created',
            'entity_type' => 'appointment_slot',
            'entity_id' => (int) $created['id'],
        ]);

        $this->postJson('/api/dashboard/appointment-slots', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.appointment_slots.duplicate_identity'));
    }

    public function test_update_requires_version_and_stale_returns_409(): void
    {
        $employee = $this->asTestEmployee();
        $slot = $this->makeSlot(['location' => 'A']);

        $this->patchJson("/api/dashboard/appointment-slots/{$slot->id}", [
            'location' => 'B',
        ])->assertStatus(422);

        $this->patchJson("/api/dashboard/appointment-slots/{$slot->id}", [
            'version' => 999,
            'location' => 'B',
        ])->assertStatus(409)
            ->assertJsonPath('message', __('messages.appointment_slots.stale_version'));

        $updated = $this->patchJson("/api/dashboard/appointment-slots/{$slot->id}", [
            'version' => 1,
            'location' => 'B',
            'reason' => 'تحديث الموقع',
        ])->assertOk()->json('data');

        $this->assertSame('B', $updated['location']);
        $this->assertSame(2, $updated['version']);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'appointment_slot',
            'entity_id' => $slot->id,
            'action' => 'appointment_slot.updated',
            'user_id' => $employee->id,
        ]);
    }

    public function test_capacity_cannot_drop_below_booked_count(): void
    {
        $this->asTestEmployee();
        $slot = $this->makeSlot(['capacity' => 5, 'booked_count' => 3]);

        $this->patchJson("/api/dashboard/appointment-slots/{$slot->id}", [
            'version' => 1,
            'capacity' => 2,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.appointment_slots.unsafe_capacity_reduction'));
    }

    public function test_identity_fields_protected_when_active_bookings_exist(): void
    {
        $this->asTestEmployee();
        $slot = $this->makeSlot();
        [$citizen, $application] = $this->citizenWithApplication();

        TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $slot->test_type_id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => now()->addDays(5),
        ]);
        $slot->update(['booked_count' => 1]);

        $this->patchJson("/api/dashboard/appointment-slots/{$slot->id}", [
            'version' => 1,
            'date' => $this->futureDate(10),
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.appointment_slots.identity_immutable_with_bookings'));
    }

    public function test_activate_and_deactivate_rules(): void
    {
        $employee = $this->asTestEmployee();
        $slot = $this->makeSlot(['is_active' => false]);

        $this->patchJson("/api/dashboard/appointment-slots/{$slot->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'appointment_slot',
            'entity_id' => $slot->id,
            'action' => 'appointment_slot.activated',
            'user_id' => $employee->id,
        ]);

        $past = $this->makeSlot([
            'date' => $this->clock()->now()->subDay()->toDateString(),
            'is_active' => false,
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->patchJson("/api/dashboard/appointment-slots/{$past->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.appointment_slots.past_slot_rejected'));

        $active = $this->makeSlot(['start_time' => '16:00:00', 'end_time' => '17:00:00']);
        $this->patchJson("/api/dashboard/appointment-slots/{$active->id}/deactivate", [])
            ->assertStatus(422);

        $this->patchJson("/api/dashboard/appointment-slots/{$active->id}/deactivate", [
            'reason' => 'إغلاق مؤقت',
        ])->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'appointment_slot',
            'entity_id' => $active->id,
            'action' => 'appointment_slot.deactivated',
        ]);
    }

    public function test_cannot_deactivate_slot_with_active_bookings_and_does_not_cancel(): void
    {
        $this->asTestEmployee();
        $slot = $this->makeSlot();
        [$citizen, $application] = $this->citizenWithApplication();

        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $slot->test_type_id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => now()->addDays(5),
        ]);
        $slot->update(['booked_count' => 1]);

        $this->patchJson("/api/dashboard/appointment-slots/{$slot->id}/deactivate", [
            'reason' => 'محاولة تعطيل',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.appointment_slots.unsafe_deactivation'));

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
    }

    public function test_bookings_endpoint_is_safe_and_filterable(): void
    {
        $this->asTestEmployee();
        $slot = $this->makeSlot();
        [$citizen, $application] = $this->citizenWithApplication();

        TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $slot->test_type_id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => now()->addDays(5),
        ]);

        $data = $this->getJson("/api/dashboard/appointment-slots/{$slot->id}/bookings?status=booked&per_page=10")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['items']);
        $row = $data['items'][0];
        $this->assertArrayHasKey('application', $row);
        $this->assertArrayHasKey('citizen', $row);
        $this->assertArrayNotHasKey('national_id', $row['citizen']);
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('email', $row['citizen']);
        $this->assertStringNotContainsString('messages.', $row['status_label']);
    }

    public function test_audit_logs_require_view_audit_logs_permission(): void
    {
        $slot = $this->makeSlot();
        $this->asTestEmployee();

        $this->getJson("/api/dashboard/appointment-slots/{$slot->id}/audit-logs")->assertForbidden();

        $this->asSuperAdmin();
        $this->getJson("/api/dashboard/appointment-slots/{$slot->id}/audit-logs")
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'pagination']]);
    }

    public function test_details_include_bookings_summary_not_all_bookings(): void
    {
        $this->asTestEmployee();
        $slot = $this->makeSlot();

        $data = $this->getJson("/api/dashboard/appointment-slots/{$slot->id}")->assertOk()->json('data');
        $this->assertArrayHasKey('bookings_summary', $data);
        $this->assertArrayNotHasKey('bookings', $data);
        $this->assertArrayHasKey('actions', $data);
        $this->assertTrue($data['actions']['can_update']);
    }

    /**
     * @return array{0: User, 1: LicenseApplication}
     */
    private function citizenWithApplication(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-SLOT-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::InTesting,
            'current_test_type_id' => $this->visionType()->id,
            'submitted_at' => now(),
        ]);

        return [$citizen, $application];
    }
}
