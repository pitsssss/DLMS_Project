<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Models\AppointmentCenter;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Database\Seeders\AppointmentCentersSeeder;
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

class AppointmentTimezoneTest extends TestCase
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

    private function citizenWithApplication(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-TZ-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::AppointmentPending,
            'current_test_type_id' => null,
            'submitted_at' => now(),
        ]);

        return [$citizen, $application];
    }

    public function test_available_slot_listing_uses_damascus_business_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 21:30:00', 'UTC'));

        $vision = $this->visionType();
        $center = $this->center();

        $todayDamascus = $this->clock()->now()->toDateString();
        $this->assertSame('2026-07-30', $todayDamascus);

        $todaySlot = AppointmentSlot::query()->create([
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => $todayDamascus,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'capacity' => 5,
            'booked_count' => 0,
            'location' => 'today',
            'is_active' => true,
            'version' => 1,
        ]);

        $yesterdaySlot = AppointmentSlot::query()->create([
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => '2026-07-29',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'capacity' => 5,
            'booked_count' => 0,
            'location' => 'yesterday',
            'is_active' => true,
            'version' => 1,
        ]);

        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($citizen);

        $response = $this->getJson('/api/appointment-slots?test_type_id='.$vision->id)->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($todaySlot->id, $ids);
        $this->assertNotContains($yesterdaySlot->id, $ids);

        CarbonImmutable::setTestNow();
    }

    public function test_booking_near_utc_damascus_boundary_uses_business_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 21:30:00', 'UTC'));

        [$citizen, $application] = $this->citizenWithApplication();
        Sanctum::actingAs($citizen);

        $vision = $this->visionType();
        $center = $this->center();
        $todayDamascus = $this->clock()->now()->toDateString();

        $slot = AppointmentSlot::query()->create([
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => $todayDamascus,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'capacity' => 2,
            'booked_count' => 0,
            'location' => 'boundary',
            'is_active' => true,
            'version' => 1,
        ]);

        $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ])->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::Booked->value);

        $slot->refresh();
        $this->assertSame(1, $slot->booked_count);

        CarbonImmutable::setTestNow();
    }

    public function test_dashboard_create_rejects_past_date_at_damascus_boundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 21:30:00', 'UTC'));

        $employee = User::factory()->dashboardEmployee('test_employee')->create();
        Sanctum::actingAs($employee);

        $vision = $this->visionType();
        $center = $this->center();

        $this->postJson('/api/dashboard/appointment-slots', [
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => '2026-07-29',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'capacity' => 2,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('messages.appointment_slots.past_date_rejected'));

        CarbonImmutable::setTestNow();
    }
}
