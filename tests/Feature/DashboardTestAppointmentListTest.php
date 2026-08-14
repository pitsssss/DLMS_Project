<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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

class DashboardTestAppointmentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $frozen = CarbonImmutable::parse('2026-08-14 14:00:00', 'Asia/Damascus');
        CarbonImmutable::setTestNow($frozen);
        Carbon::setTestNow($frozen);
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

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_employee_can_list_appointments_awaiting_result(): void
    {
        $examiner = $this->asExaminer();
        $waiting = $this->bookedAppointment(ApplicationStatus::InTesting);

        $response = $this->getJson('/api/dashboard/test-appointments')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $waiting->id)
            ->assertJsonPath('data.items.0.status', AppointmentStatus::Booked->value)
            ->assertJsonPath('data.items.0.actions.can_record_result', true);

        $this->assertSame(__('messages.tests.dashboard_list_retrieved'), $response->json('message'));
    }

    public function test_appointment_with_existing_result_is_excluded(): void
    {
        $this->asExaminer();
        $withResult = $this->bookedAppointment(ApplicationStatus::InTesting);
        $this->recordResult($withResult, TestResultStatus::Passed);
        $waiting = $this->bookedAppointment(ApplicationStatus::InTesting);

        $this->getJson('/api/dashboard/test-appointments')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $waiting->id);
    }

    public function test_non_booked_appointment_is_excluded_from_waiting_result_filter(): void
    {
        $this->asExaminer();
        $this->appointment(ApplicationStatus::InTesting, AppointmentStatus::Completed);
        $this->appointment(ApplicationStatus::InTesting, AppointmentStatus::Cancelled);
        $this->appointment(ApplicationStatus::InTesting, AppointmentStatus::NoShow);
        $waiting = $this->bookedAppointment(ApplicationStatus::WaitingRetest);

        $this->getJson('/api/dashboard/test-appointments')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $waiting->id);

        $this->getJson('/api/dashboard/test-appointments?status=completed')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.status', AppointmentStatus::Completed->value);
    }

    public function test_unauthorized_employee_cannot_access(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('fines_employee')->create());

        $this->getJson('/api/dashboard/test-appointments')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/dashboard/test-appointments')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_unauthenticated_dashboard_request_without_json_accept_returns_401_json(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/html'])
            ->get('/api/dashboard/test-appointments');

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<html', strtolower($response->getContent()));
    }

    public function test_unauthenticated_admin_record_result_without_json_accept_returns_401_json(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/html'])
            ->post('/api/admin/test-appointments/1/record-result', [
                'result' => 'passed',
            ]);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<html', strtolower($response->getContent()));
    }

    public function test_citizen_cannot_access(): void
    {
        Sanctum::actingAs(User::factory()->withApprovedProfile()->create([
            'user_type' => UserType::Citizen,
            'email_verified_at' => now(),
        ]));

        $this->getJson('/api/dashboard/test-appointments')->assertForbidden();
    }

    public function test_expected_fields_and_actions_are_returned(): void
    {
        $this->asExaminer();
        $appointment = $this->bookedAppointment(ApplicationStatus::InTesting, [
            'name' => 'Examiner Target',
        ]);
        $this->seedPriorFailedAttempt($appointment);

        $item = $this->getJson('/api/dashboard/test-appointments')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $appointment->id)
            ->json('data.items.0');

        $this->assertSame($appointment->id, $item['id']);
        $this->assertNotEmpty($item['scheduled_at']);
        $this->assertSame(AppointmentStatus::Booked->value, $item['status']);
        $this->assertNotEmpty($item['status_label']);

        $this->assertSame($appointment->application_id, $item['application']['id']);
        $this->assertSame($appointment->application->application_number, $item['application']['application_number']);
        $this->assertSame(ApplicationStatus::InTesting->value, $item['application']['status']['value']);
        $this->assertNotEmpty($item['application']['status']['label']);

        $this->assertSame($appointment->citizen_id, $item['citizen']['id']);
        $this->assertSame('Examiner Target', $item['citizen']['name']);
        $this->assertArrayNotHasKey('phone', $item['citizen']);
        $this->assertArrayNotHasKey('national_id', $item['citizen']);

        $this->assertSame($appointment->test_type_id, $item['test_type']['id']);
        $this->assertSame('vision', $item['test_type']['code']);
        $this->assertNotEmpty($item['test_type']['name']);

        $this->assertSame(1, $item['previous_attempts_count']);
        $this->assertSame(2, $item['next_attempt_number']);

        $this->assertSame($appointment->appointment_slot_id, $item['slot']['id']);
        $this->assertArrayHasKey('date', $item['slot']);
        $this->assertArrayHasKey('start_time', $item['slot']);
        $this->assertArrayHasKey('location', $item['slot']);
        $this->assertArrayHasKey('appointment_center', $item['slot']);

        $this->assertTrue($item['actions']['can_record_result']);
        $this->assertFalse($item['actions']['can_view_application']);
    }

    public function test_view_only_appointment_staff_can_list_but_cannot_record(): void
    {
        $role = Role::query()->create([
            'name' => 'appointments_viewer_'.Str::random(4),
            'display_name' => 'Appointments Viewer',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('name', ['access_dashboard', 'view_appointments'])->pluck('id')
        );

        $viewer = User::factory()->create([
            'user_type' => UserType::Employee,
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed' => true,
        ]);
        Sanctum::actingAs($viewer);

        $this->bookedAppointment(ApplicationStatus::InTesting);

        $this->getJson('/api/dashboard/test-appointments')
            ->assertOk()
            ->assertJsonPath('data.items.0.actions.can_record_result', false)
            ->assertJsonPath('data.items.0.actions.can_view_application', false);
    }

    public function test_pagination_and_filtering_behavior(): void
    {
        $this->asExaminer();
        $vision = $this->bookedAppointment(ApplicationStatus::InTesting);
        $theory = $this->bookedAppointment(ApplicationStatus::InTesting, [], 'theory');
        $otherDay = $this->bookedAppointment(ApplicationStatus::InTesting, [], 'vision', now()->addDay());

        $this->getJson('/api/dashboard/test-appointments?test_type_code=theory')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $theory->id);

        $this->getJson('/api/dashboard/test-appointments?test_type_id='.$vision->test_type_id)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson('/api/dashboard/test-appointments?search='.$vision->application->application_number)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $vision->id);

        $this->getJson('/api/dashboard/test-appointments?date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson('/api/dashboard/test-appointments?date='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $otherDay->id);

        for ($i = 0; $i < 9; $i++) {
            $this->bookedAppointment(ApplicationStatus::InTesting);
        }

        $this->getJson('/api/dashboard/test-appointments?per_page=5')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonCount(5, 'data.items');
    }

    public function test_existing_record_result_endpoint_still_passes_after_list(): void
    {
        $this->asExaminer();
        $appointment = $this->bookedAppointment(ApplicationStatus::InTesting);

        $this->getJson('/api/dashboard/test-appointments')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $appointment->id)
            ->assertJsonPath('data.items.0.actions.can_record_result', true);

        $this->postJson("/api/admin/test-appointments/{$appointment->id}/record-result", [
            'result' => TestResultStatus::Passed->value,
            'notes' => 'Clear vision',
        ])->assertOk()
            ->assertJsonPath('data.result', TestResultStatus::Passed->value)
            ->assertJsonPath('data.notes', 'Clear vision');

        $this->getJson('/api/dashboard/test-appointments')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_generic_employee_with_record_permission_can_list(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('employee')->create());
        $appointment = $this->bookedAppointment(ApplicationStatus::InTesting);

        $this->getJson('/api/dashboard/test-appointments')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $appointment->id)
            ->assertJsonPath('data.items.0.actions.can_record_result', true);
    }

    private function asExaminer(): User
    {
        $user = User::factory()->dashboardEmployee('test_employee')->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $citizenOverrides
     */
    private function bookedAppointment(
        ApplicationStatus $applicationStatus,
        array $citizenOverrides = [],
        string $testTypeCode = 'vision',
        mixed $scheduledAt = null,
    ): TestAppointment {
        return $this->appointment($applicationStatus, AppointmentStatus::Booked, $citizenOverrides, $testTypeCode, $scheduledAt);
    }

    /**
     * @param  array<string, mixed>  $citizenOverrides
     */
    private function appointment(
        ApplicationStatus $applicationStatus,
        AppointmentStatus $appointmentStatus,
        array $citizenOverrides = [],
        string $testTypeCode = 'vision',
        mixed $scheduledAt = null,
    ): TestAppointment {
        $citizen = User::factory()->withApprovedProfile()->create(array_merge([
            'user_type' => UserType::Citizen,
            'email_verified_at' => now(),
        ], $citizenOverrides));

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-TA-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => $applicationStatus,
            'submitted_at' => now(),
        ]);

        $testType = TestType::query()->where('code', $testTypeCode)->firstOrFail();
        $slot = AppointmentSlot::query()
            ->where('test_type_id', $testType->id)
            ->where('is_active', true)
            ->firstOrFail();

        return TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => $appointmentStatus,
            'scheduled_at' => $scheduledAt ?? now(),
        ]);
    }

    private function recordResult(TestAppointment $appointment, TestResultStatus $result): void
    {
        TestResult::query()->create([
            'application_id' => $appointment->application_id,
            'test_appointment_id' => $appointment->id,
            'test_type_id' => $appointment->test_type_id,
            'result' => $result,
            'attempt_number' => 1,
            'notes' => null,
            'recorded_by' => User::factory()->dashboardEmployee('test_employee')->create()->id,
            'recorded_at' => now(),
        ]);
    }

    private function seedPriorFailedAttempt(TestAppointment $current): void
    {
        $prior = TestAppointment::query()->create([
            'application_id' => $current->application_id,
            'citizen_id' => $current->citizen_id,
            'appointment_slot_id' => $current->appointment_slot_id,
            'test_type_id' => $current->test_type_id,
            'status' => AppointmentStatus::Completed,
            'scheduled_at' => now()->subDay(),
        ]);

        TestResult::query()->create([
            'application_id' => $current->application_id,
            'test_appointment_id' => $prior->id,
            'test_type_id' => $current->test_type_id,
            'result' => TestResultStatus::Failed,
            'attempt_number' => 1,
            'notes' => null,
            'recorded_by' => User::factory()->dashboardEmployee('test_employee')->create()->id,
            'recorded_at' => now()->subDay(),
        ]);
    }
}
