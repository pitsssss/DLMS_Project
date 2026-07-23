<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\LicenseType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardLicenseTypesTest extends TestCase
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

    private function createLicenseType(array $overrides = []): LicenseType
    {
        return LicenseType::query()->create(array_merge([
            'name' => 'رخصة اختبار',
            'code' => 'test_lt_'.uniqid(),
            'minimum_age' => 18,
            'validity_years' => 5,
            'is_active' => true,
        ], $overrides));
    }

    public function test_unauthenticated_receives_401(): void
    {
        $this->getJson('/api/dashboard/license-types')->assertUnauthorized();
    }

    public function test_citizen_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['user_type' => UserType::Citizen]));

        $this->getJson('/api/dashboard/license-types')->assertForbidden();
    }

    public function test_dashboard_user_without_permission_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('profile_document_reviewer')->create());

        $this->getJson('/api/dashboard/license-types')->assertForbidden();
        $this->postJson('/api/dashboard/license-types', [
            'name' => 'x',
            'code' => 'x',
            'minimum_age' => 18,
            'validity_years' => 5,
        ])->assertForbidden();
    }

    public function test_authorized_user_can_list_license_types(): void
    {
        $this->asSettingsAdmin();
        $this->createLicenseType(['name' => 'خاصة', 'code' => 'private_list']);

        $this->getJson('/api/dashboard/license-types')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => [['id', 'name', 'code', 'minimum_age', 'validity_years', 'is_active', 'created_at', 'updated_at']],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                    'statistics' => ['total', 'active', 'inactive'],
                ],
            ]);
    }

    public function test_search_by_name_and_code_works(): void
    {
        $this->asSettingsAdmin();
        $this->createLicenseType(['name' => 'رخصة خاصة بحث', 'code' => 'search_private']);
        $this->createLicenseType(['name' => 'رخصة عامة', 'code' => 'search_public']);

        $byName = $this->getJson('/api/dashboard/license-types?search='.urlencode('خاصة بحث'))
            ->assertOk()
            ->json('data.items');
        $this->assertCount(1, $byName);
        $this->assertSame('search_private', $byName[0]['code']);

        $byCode = $this->getJson('/api/dashboard/license-types?search=search_public')
            ->assertOk()
            ->json('data.items');
        $this->assertCount(1, $byCode);
        $this->assertSame('search_public', $byCode[0]['code']);
    }

    public function test_active_and_inactive_filters_work(): void
    {
        $this->asSettingsAdmin();
        $this->createLicenseType(['code' => 'flt_active', 'is_active' => true]);
        $this->createLicenseType(['code' => 'flt_inactive', 'is_active' => false]);

        $active = collect($this->getJson('/api/dashboard/license-types?is_active=1&per_page=50')->json('data.items'));
        $this->assertTrue($active->every(fn ($i) => $i['is_active'] === true));
        $this->assertTrue($active->contains(fn ($i) => $i['code'] === 'flt_active'));
        $this->assertFalse($active->contains(fn ($i) => $i['code'] === 'flt_inactive'));

        $inactive = collect($this->getJson('/api/dashboard/license-types?is_active=0&per_page=50')->json('data.items'));
        $this->assertTrue($inactive->every(fn ($i) => $i['is_active'] === false));
        $this->assertTrue($inactive->contains(fn ($i) => $i['code'] === 'flt_inactive'));

        $both = collect($this->getJson('/api/dashboard/license-types?per_page=50')->json('data.items'))->pluck('code');
        $this->assertTrue($both->contains('flt_active'));
        $this->assertTrue($both->contains('flt_inactive'));
    }

    public function test_pagination_and_per_page_options(): void
    {
        $this->asSettingsAdmin();
        for ($i = 0; $i < 12; $i++) {
            $this->createLicenseType(['code' => "page_lt_{$i}"]);
        }

        $default = $this->getJson('/api/dashboard/license-types')->assertOk();
        $this->assertSame(20, $default->json('data.pagination.per_page'));

        foreach ([10, 20, 25, 50] as $perPage) {
            $this->getJson('/api/dashboard/license-types?per_page='.$perPage)
                ->assertOk()
                ->assertJsonPath('data.pagination.per_page', $perPage);
        }

        $this->getJson('/api/dashboard/license-types?per_page=15')->assertStatus(422);
        $this->getJson('/api/dashboard/license-types?sort_by=password')->assertStatus(422);
    }

    public function test_safe_sorting_works(): void
    {
        $this->asSettingsAdmin();
        $this->createLicenseType(['name' => 'Z Type', 'code' => 'sort_z']);
        $this->createLicenseType(['name' => 'A Type', 'code' => 'sort_a']);

        $names = collect($this->getJson('/api/dashboard/license-types?sort_by=name&sort_direction=asc&per_page=50')
            ->assertOk()
            ->json('data.items'))
            ->pluck('name')
            ->all();

        $this->assertTrue(array_search('A Type', $names, true) < array_search('Z Type', $names, true));
    }

    public function test_statistics_are_global(): void
    {
        $this->asSettingsAdmin();
        $before = $this->getJson('/api/dashboard/license-types')->json('data.statistics');

        $this->createLicenseType(['code' => 'stat_a', 'is_active' => true]);
        $this->createLicenseType(['code' => 'stat_b', 'is_active' => false]);

        $stats = $this->getJson('/api/dashboard/license-types?is_active=1&per_page=10')
            ->assertOk()
            ->json('data.statistics');

        $this->assertSame($before['total'] + 2, $stats['total']);
        $this->assertSame($before['active'] + 1, $stats['active']);
        $this->assertSame($before['inactive'] + 1, $stats['inactive']);
    }

    public function test_create_update_and_code_immutability(): void
    {
        $this->asSettingsAdmin();

        $created = $this->postJson('/api/dashboard/license-types', [
            'name' => 'نوع جديد',
            'code' => 'New_Custom',
            'minimum_age' => 18,
            'validity_years' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('message', __('messages.dashboard.license_type_created'))
            ->assertJsonPath('data.code', 'new_custom');

        $id = $created->json('data.id');

        $this->postJson('/api/dashboard/license-types', [
            'name' => 'مكرر',
            'code' => 'new_custom',
            'minimum_age' => 18,
            'validity_years' => 5,
        ])->assertStatus(422);

        $this->postJson('/api/dashboard/license-types', [
            'name' => 'عمر خاطئ',
            'code' => 'bad_age',
            'minimum_age' => 10,
            'validity_years' => 5,
        ])->assertStatus(422);

        $this->postJson('/api/dashboard/license-types', [
            'name' => 'صلاحية خاطئة',
            'code' => 'bad_years',
            'minimum_age' => 18,
            'validity_years' => 0,
        ])->assertStatus(422);

        $this->patchJson("/api/dashboard/license-types/{$id}", [
            'name' => 'اسم محدث',
            'minimum_age' => 21,
            'validity_years' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'اسم محدث')
            ->assertJsonPath('data.code', 'new_custom');

        $this->patchJson("/api/dashboard/license-types/{$id}", [
            'code' => 'changed_code',
        ])->assertStatus(422);

        $this->assertDatabaseHas('license_types', [
            'id' => $id,
            'code' => 'new_custom',
            'name' => 'اسم محدث',
        ]);
    }

    public function test_activate_deactivate_idempotent_and_audited(): void
    {
        $this->asSettingsAdmin();
        $type = $this->createLicenseType(['code' => 'act_lt', 'is_active' => true]);

        $this->patchJson("/api/dashboard/license-types/{$type->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('message', __('messages.dashboard.license_type_deactivated'));

        $this->patchJson("/api/dashboard/license-types/{$type->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/dashboard/license-types/{$type->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->patchJson("/api/dashboard/license-types/{$type->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'license_type.deactivated',
            'entity_type' => 'license_type',
            'entity_id' => $type->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'license_type.activated',
            'entity_type' => 'license_type',
            'entity_id' => $type->id,
        ]);
    }

    public function test_show_and_missing_record(): void
    {
        $this->asSettingsAdmin();
        $type = $this->createLicenseType(['code' => 'show_lt']);

        $this->getJson("/api/dashboard/license-types/{$type->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'show_lt');

        $this->getJson('/api/dashboard/license-types/999999')->assertNotFound();
    }

    public function test_deactivated_type_excluded_from_public_api(): void
    {
        $this->asSettingsAdmin();
        $type = $this->createLicenseType(['code' => 'public_hide_lt', 'is_active' => true]);

        $this->patchJson("/api/dashboard/license-types/{$type->id}/deactivate")->assertOk();

        $codes = collect($this->getJson('/api/license-types')->assertOk()->json('data'))->pluck('code');
        $this->assertFalse($codes->contains('public_hide_lt'));
        $this->assertDatabaseHas('license_types', ['id' => $type->id, 'is_active' => false]);
    }

    public function test_no_delete_route(): void
    {
        $this->asSettingsAdmin();
        $type = $this->createLicenseType(['code' => 'no_del_lt']);

        $this->deleteJson("/api/dashboard/license-types/{$type->id}")->assertStatus(405);
    }
}
