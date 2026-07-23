<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Enums\ApplicationStatus;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardServiceTypesTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function asSettingsAdmin(): User
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function createServiceType(array $overrides = []): ServiceType
    {
        return ServiceType::query()->create(array_merge([
            'name' => 'خدمة اختبار',
            'code' => 'test_st_'.uniqid(),
            'description' => 'وصف تجريبي',
            'is_active' => true,
        ], $overrides));
    }

    public function test_unauthenticated_receives_401(): void
    {
        $this->getJson('/api/dashboard/service-types')->assertUnauthorized();
    }

    public function test_citizen_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['user_type' => UserType::Citizen]));

        $this->getJson('/api/dashboard/service-types')->assertForbidden();
    }

    public function test_dashboard_user_without_permission_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('profile_document_reviewer')->create());

        $this->getJson('/api/dashboard/service-types')->assertForbidden();
    }

    public function test_authorized_user_can_list_service_types(): void
    {
        $this->asSettingsAdmin();
        $this->createServiceType(['code' => 'list_st']);

        $this->getJson('/api/dashboard/service-types')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => [['id', 'name', 'code', 'description', 'is_active', 'created_at', 'updated_at']],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                    'statistics' => ['total', 'active', 'inactive'],
                ],
            ]);
    }

    public function test_search_by_name_code_and_description(): void
    {
        $this->asSettingsAdmin();
        $this->createServiceType([
            'name' => 'تجديد خاص',
            'code' => 'search_renew',
            'description' => 'وصف فريد للتجديد',
        ]);
        $this->createServiceType([
            'name' => 'إصدار',
            'code' => 'search_new',
            'description' => 'وصف آخر',
        ]);

        $this->assertCount(1, $this->getJson('/api/dashboard/service-types?search='.urlencode('تجديد خاص'))
            ->assertOk()->json('data.items'));
        $this->assertCount(1, $this->getJson('/api/dashboard/service-types?search=search_renew')
            ->assertOk()->json('data.items'));
        $this->assertCount(1, $this->getJson('/api/dashboard/service-types?search='.urlencode('فريد للتجديد'))
            ->assertOk()->json('data.items'));
    }

    public function test_active_inactive_and_missing_filters(): void
    {
        $this->asSettingsAdmin();
        $this->createServiceType(['code' => 'st_active', 'is_active' => true]);
        $this->createServiceType(['code' => 'st_inactive', 'is_active' => false]);

        $active = collect($this->getJson('/api/dashboard/service-types?is_active=1&per_page=50')->json('data.items'));
        $this->assertTrue($active->every(fn ($i) => $i['is_active'] === true));
        $this->assertFalse($active->contains(fn ($i) => $i['code'] === 'st_inactive'));

        $inactive = collect($this->getJson('/api/dashboard/service-types?is_active=0&per_page=50')->json('data.items'));
        $this->assertTrue($inactive->contains(fn ($i) => $i['code'] === 'st_inactive'));

        $both = collect($this->getJson('/api/dashboard/service-types?per_page=50')->json('data.items'))->pluck('code');
        $this->assertTrue($both->contains('st_active'));
        $this->assertTrue($both->contains('st_inactive'));
    }

    public function test_pagination_sorting_and_validation(): void
    {
        $this->asSettingsAdmin();
        for ($i = 0; $i < 11; $i++) {
            $this->createServiceType(['code' => "page_st_{$i}"]);
        }

        $this->assertSame(20, $this->getJson('/api/dashboard/service-types')->json('data.pagination.per_page'));

        foreach ([10, 20, 25, 50] as $perPage) {
            $this->getJson('/api/dashboard/service-types?per_page='.$perPage)
                ->assertOk()
                ->assertJsonPath('data.pagination.per_page', $perPage);
        }

        $this->getJson('/api/dashboard/service-types?per_page=7')->assertStatus(422);
        $this->getJson('/api/dashboard/service-types?sort_by=secret')->assertStatus(422);
        $this->getJson('/api/dashboard/service-types?sort_direction=up')->assertStatus(422);

        $this->createServiceType(['name' => 'Z Service', 'code' => 'sort_st_z']);
        $this->createServiceType(['name' => 'A Service', 'code' => 'sort_st_a']);
        $names = collect($this->getJson('/api/dashboard/service-types?sort_by=name&sort_direction=asc&per_page=50')
            ->json('data.items'))->pluck('name')->all();
        $this->assertTrue(array_search('A Service', $names, true) < array_search('Z Service', $names, true));
    }

    public function test_statistics_are_global(): void
    {
        $this->asSettingsAdmin();
        $before = $this->getJson('/api/dashboard/service-types')->json('data.statistics');

        $this->createServiceType(['code' => 'stat_st_a', 'is_active' => true]);
        $this->createServiceType(['code' => 'stat_st_b', 'is_active' => false]);

        $stats = $this->getJson('/api/dashboard/service-types?is_active=1')
            ->assertOk()
            ->json('data.statistics');

        $this->assertSame($before['total'] + 2, $stats['total']);
        $this->assertSame($before['active'] + 1, $stats['active']);
        $this->assertSame($before['inactive'] + 1, $stats['inactive']);
    }

    public function test_create_update_duplicate_and_code_immutable(): void
    {
        $this->asSettingsAdmin();

        $created = $this->postJson('/api/dashboard/service-types', [
            'name' => 'خدمة مخصصة',
            'code' => 'Custom_Svc',
            'description' => 'وصف',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'custom_svc')
            ->assertJsonPath('message', __('messages.dashboard.service_type_created'));

        $id = $created->json('data.id');

        $this->postJson('/api/dashboard/service-types', [
            'name' => 'مكرر',
            'code' => 'custom_svc',
        ])->assertStatus(422);

        $this->patchJson("/api/dashboard/service-types/{$id}", [
            'name' => 'اسم محدث',
            'description' => 'وصف محدث',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'اسم محدث')
            ->assertJsonPath('data.code', 'custom_svc');

        $this->patchJson("/api/dashboard/service-types/{$id}", [
            'code' => 'renew_license',
        ])->assertStatus(422);

        $this->assertDatabaseHas('service_types', [
            'id' => $id,
            'code' => 'custom_svc',
            'name' => 'اسم محدث',
        ]);
    }

    public function test_activate_deactivate_preserves_existing_applications(): void
    {
        $this->seed([LicenseTypesSeeder::class, ServiceTypesSeeder::class]);
        $this->asSettingsAdmin();

        $service = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $citizen = User::factory()->withApprovedProfile()->create();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-ST-KEEP-1',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $service->id,
            'status' => ApplicationStatus::Draft,
        ]);

        $this->patchJson("/api/dashboard/service-types/{$service->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('license_applications', [
            'id' => $application->id,
            'service_type_id' => $service->id,
        ]);

        $this->patchJson("/api/dashboard/service-types/{$service->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'service_type.deactivated',
            'entity_id' => $service->id,
        ]);
    }

    public function test_deactivated_service_excluded_from_public_api(): void
    {
        $this->asSettingsAdmin();
        $type = $this->createServiceType(['code' => 'public_hide_st', 'is_active' => true]);

        $this->patchJson("/api/dashboard/service-types/{$type->id}/deactivate")->assertOk();

        $codes = collect($this->getJson('/api/service-types')->assertOk()->json('data'))->pluck('code');
        $this->assertFalse($codes->contains('public_hide_st'));
    }

    public function test_show_missing_and_no_delete(): void
    {
        $this->asSettingsAdmin();
        $type = $this->createServiceType(['code' => 'show_st']);

        $this->getJson("/api/dashboard/service-types/{$type->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'show_st');

        $this->getJson('/api/dashboard/service-types/999999')->assertNotFound();
        $this->deleteJson("/api/dashboard/service-types/{$type->id}")->assertStatus(405);
    }

    public function test_repeated_activate_deactivate_are_safe(): void
    {
        $this->asSettingsAdmin();
        $type = $this->createServiceType(['code' => 'idem_st', 'is_active' => false]);

        $this->patchJson("/api/dashboard/service-types/{$type->id}/deactivate")->assertOk();
        $this->patchJson("/api/dashboard/service-types/{$type->id}/activate")->assertOk();
        $this->patchJson("/api/dashboard/service-types/{$type->id}/activate")->assertOk();
        $this->assertTrue($type->fresh()->is_active);
    }
}
