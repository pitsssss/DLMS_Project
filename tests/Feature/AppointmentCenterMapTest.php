<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\AppointmentCenter;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use App\Modules\Appointments\Support\AppointmentCenterMapUrlBuilder;
use Database\Seeders\AppointmentCentersSeeder;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AppointmentCenterMapTest extends TestCase
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
            AppointmentCentersSeeder::class,
            AppointmentSlotsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function appointmentPendingApplication(User $citizen): LicenseApplication
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return LicenseApplication::query()->create([
            'application_number' => 'APP-MAP-'.uniqid(),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::AppointmentPending,
        ]);
    }

    private function visionSlot(): AppointmentSlot
    {
        $vision = TestType::query()->where('code', 'vision')->firstOrFail();

        return AppointmentSlot::query()
            ->where('test_type_id', $vision->id)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->with('appointmentCenter')
            ->firstOrFail();
    }

    public function test_map_urls_are_generated_from_coordinates(): void
    {
        $urls = AppointmentCenterMapUrlBuilder::urls(33.5138, 36.2765);

        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query=33.5138,36.2765',
            $urls['map_url']
        );
        $this->assertSame(
            'https://www.google.com/maps/dir/?api=1&destination=33.5138,36.2765',
            $urls['directions_url']
        );
        $this->assertStringNotContainsString('key=', json_encode($urls));
    }

    public function test_map_urls_fallback_to_encoded_address_when_coordinates_missing(): void
    {
        $address = 'شارع الملك فيصل، الحي الحكومي';
        $urls = AppointmentCenterMapUrlBuilder::urls(null, null, $address);

        $this->assertStringContainsString(
            'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address),
            (string) $urls['map_url']
        );
        $this->assertStringContainsString(
            'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address),
            (string) $urls['directions_url']
        );
    }

    public function test_map_urls_are_null_when_coordinates_and_address_missing(): void
    {
        $urls = AppointmentCenterMapUrlBuilder::urls(null, null, null);

        $this->assertNull($urls['map_url']);
        $this->assertNull($urls['directions_url']);
    }

    public function test_available_slots_api_includes_center_map_links(): void
    {
        Sanctum::actingAs($this->citizen());

        $vision = TestType::query()->where('code', 'vision')->firstOrFail();

        $response = $this->getJson('/api/appointment-slots?test_type_id='.$vision->id)->assertOk();

        $slot = $response->json('data.0');
        $this->assertNotNull($slot['center']);
        $this->assertArrayHasKey('map_url', $slot['center']);
        $this->assertArrayHasKey('directions_url', $slot['center']);
        $this->assertStringContainsString('google.com/maps/search/', (string) $slot['center']['map_url']);
        $this->assertStringContainsString('33.5138,36.2765', (string) $slot['center']['map_url']);
        $this->assertStringNotContainsString('key=', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_booked_appointment_api_includes_center_map_links(): void
    {
        $citizen = $this->citizen();
        $application = $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);

        $slot = $this->visionSlot();

        $book = $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ])->assertOk();

        $center = $book->json('data.appointment_slot.center');
        $this->assertNotNull($center);
        $this->assertNotNull($center['map_url']);
        $this->assertNotNull($center['directions_url']);
        $this->assertSame('المركز الرئيسي', $center['name']);

        $list = $this->getJson("/api/applications/{$application->id}/appointments")->assertOk();
        $listedCenter = $list->json('data.0.appointment_slot.center');
        $this->assertNotNull($listedCenter['map_url']);
        $this->assertNotNull($listedCenter['directions_url']);
    }

    public function test_ai_agent_appointment_slots_include_center_map_links(): void
    {
        $citizen = $this->citizen();
        $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);

        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'اعرض المواعيد المتاحة لفحص النظر',
        ])->assertOk();

        $slot = $response->json('data.result.slots.0');
        $this->assertNotNull($slot['center']['map_url']);
        $this->assertNotNull($slot['center']['directions_url']);
        $this->assertStringContainsString('google.com/maps/dir/', (string) $slot['center']['directions_url']);
    }

    public function test_ai_agent_current_appointments_include_center_map_links(): void
    {
        $citizen = $this->citizen();
        $application = $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);

        $slot = $this->visionSlot();
        $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $slot->id,
        ])->assertOk();

        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'شو موعدي؟',
        ])->assertOk();

        $appointment = $response->json('data.result.appointments.0');
        $this->assertNotNull($appointment['center']['map_url']);
        $this->assertNotNull($appointment['center']['directions_url']);
    }

    public function test_ai_agent_book_appointment_confirm_includes_center_map_links(): void
    {
        $citizen = $this->citizen();
        $application = $this->appointmentPendingApplication($citizen);
        Sanctum::actingAs($citizen);

        $session = AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => 'active',
            'context' => [],
        ]);

        $action = AIAgentAction::query()->create([
            'session_id' => $session->id,
            'user_id' => $citizen->id,
            'action_name' => 'book_appointment',
            'arguments' => [
                'application_id' => $application->id,
                'appointment_slot_id' => $this->visionSlot()->id,
            ],
            'status' => AgentActionStatus::AwaitingConfirmation,
            'requires_confirmation' => true,
            'confirmation_message' => 'تأكيد؟',
        ]);

        $response = $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")->assertOk();

        $center = $response->json('data.result.center');
        $this->assertNotNull($center);
        $this->assertNotNull($center['map_url']);
        $this->assertNotNull($center['directions_url']);
        $this->assertSame('شارع الملك فيصل، الحي الحكومي', $center['address']);
    }

    public function test_slot_without_center_uses_location_address_fallback(): void
    {
        Sanctum::actingAs($this->citizen());

        $vision = TestType::query()->where('code', 'vision')->firstOrFail();

        $slot = AppointmentSlot::query()->create([
            'test_type_id' => $vision->id,
            'appointment_center_id' => null,
            'date' => now()->addDays(20)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'capacity' => 5,
            'booked_count' => 0,
            'location' => 'شارع الملك فيصل، الحي الحكومي',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/appointment-slots?test_type_id='.$vision->id)->assertOk();

        $found = collect($response->json('data'))->firstWhere('id', $slot->id);
        $this->assertNotNull($found);
        $this->assertNull($found['center']['id']);
        $this->assertStringContainsString(
            rawurlencode('شارع الملك فيصل، الحي الحكومي'),
            (string) $found['center']['map_url']
        );
    }

    public function test_center_with_address_only_generates_map_links(): void
    {
        Sanctum::actingAs($this->citizen());

        $center = AppointmentCenter::query()->create([
            'name' => 'مركز بدون إحداثيات',
            'address' => 'دمشق، ساحة الأمويين',
            'latitude' => null,
            'longitude' => null,
            'is_active' => true,
        ]);

        $vision = TestType::query()->where('code', 'vision')->firstOrFail();

        AppointmentSlot::query()->create([
            'test_type_id' => $vision->id,
            'appointment_center_id' => $center->id,
            'date' => now()->addDays(21)->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'capacity' => 5,
            'booked_count' => 0,
            'location' => $center->name,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/appointment-slots?test_type_id='.$vision->id)->assertOk();

        $found = collect($response->json('data'))->first(
            fn (array $item): bool => ($item['center']['name'] ?? '') === 'مركز بدون إحداثيات'
        );

        $this->assertNotNull($found);
        $this->assertNull($found['center']['latitude']);
        $this->assertStringContainsString(
            rawurlencode('دمشق، ساحة الأمويين'),
            (string) $found['center']['map_url']
        );
    }
}
