<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\User;
use App\Modules\Applications\Services\LicenseServiceEligibilityService;
use Database\Seeders\DemoLicenseServiceTestingSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class DemoLicenseServiceSeedersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function runDemoSeeder(): void
    {
        $this->seed(DemoLicenseServiceTestingSeeder::class);
    }

    public function test_demo_citizens_exist_with_approved_profiles(): void
    {
        $this->runDemoSeeder();

        foreach ([
            'renew.citizen@example.com',
            'lost.citizen@example.com',
            'damaged.citizen@example.com',
        ] as $email) {
            $user = User::query()->where('email', $email)->first();
            $this->assertNotNull($user, "Missing demo user: {$email}");
            $this->assertTrue($user->profile_completed);
            $this->assertTrue($user->canUseCitizenServices());
            $this->assertNotNull($user->email_verified_at);
        }
    }

    public function test_each_demo_citizen_has_expected_license(): void
    {
        $this->runDemoSeeder();

        $this->assertDatabaseHas('licenses', ['license_number' => 'LIC-RENEW-2026-0001']);
        $this->assertDatabaseHas('licenses', ['license_number' => 'LIC-LOST-2026-0001']);
        $this->assertDatabaseHas('licenses', ['license_number' => 'LIC-DAMAGED-2026-0001']);

        $renewCitizen = User::query()->where('email', 'renew.citizen@example.com')->firstOrFail();
        $lostCitizen = User::query()->where('email', 'lost.citizen@example.com')->firstOrFail();
        $damagedCitizen = User::query()->where('email', 'damaged.citizen@example.com')->firstOrFail();

        $this->assertGreaterThanOrEqual(1, License::query()->where('citizen_id', $renewCitizen->id)->count());
        $this->assertGreaterThanOrEqual(1, License::query()->where('citizen_id', $lostCitizen->id)->count());
        $this->assertGreaterThanOrEqual(1, License::query()->where('citizen_id', $damagedCitizen->id)->count());
    }

    public function test_eligibility_flags_match_intended_demo_purpose(): void
    {
        $this->runDemoSeeder();

        $eligibility = app(LicenseServiceEligibilityService::class);

        $renewLicense = License::query()->where('license_number', 'LIC-RENEW-2026-0001')->firstOrFail();
        $lostLicense = License::query()->where('license_number', 'LIC-LOST-2026-0001')->firstOrFail();
        $damagedLicense = License::query()->where('license_number', 'LIC-DAMAGED-2026-0001')->firstOrFail();

        $renewCitizen = User::query()->findOrFail($renewLicense->citizen_id);
        $lostCitizen = User::query()->findOrFail($lostLicense->citizen_id);
        $damagedCitizen = User::query()->findOrFail($damagedLicense->citizen_id);

        $renewFlags = $eligibility->flagsForCitizen($renewCitizen, $renewLicense);
        $lostFlags = $eligibility->flagsForCitizen($lostCitizen, $lostLicense);
        $damagedFlags = $eligibility->flagsForCitizen($damagedCitizen, $damagedLicense);

        $this->assertTrue($renewFlags['can_renew']);
        $this->assertTrue($lostFlags['can_request_lost_replacement']);
        $this->assertTrue($damagedFlags['can_request_damaged_replacement']);
    }

    public function test_running_seeder_twice_does_not_duplicate_licenses(): void
    {
        $this->runDemoSeeder();
        $this->runDemoSeeder();

        $this->assertEquals(1, License::query()->where('license_number', 'LIC-RENEW-2026-0001')->count());
        $this->assertEquals(1, License::query()->where('license_number', 'LIC-LOST-2026-0001')->count());
        $this->assertEquals(1, License::query()->where('license_number', 'LIC-DAMAGED-2026-0001')->count());
        $this->assertEquals(1, User::query()->where('email', 'renew.citizen@example.com')->count());
    }

    public function test_demo_citizens_can_login(): void
    {
        $this->runDemoSeeder();

        foreach ([
            'renew.citizen@example.com',
            'lost.citizen@example.com',
            'damaged.citizen@example.com',
        ] as $email) {
            $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'password123',
            ])
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonStructure(['data' => ['token', 'user']]);
        }
    }

    public function test_renew_citizen_can_list_licenses_with_eligibility_flags(): void
    {
        $this->runDemoSeeder();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'renew.citizen@example.com',
            'password' => 'password123',
        ])->assertOk();

        $token = $login->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/licenses')
            ->assertOk()
            ->assertJsonPath('data.0.license_number', 'LIC-RENEW-2026-0001')
            ->assertJsonPath('data.0.can_renew', true);
    }
}
