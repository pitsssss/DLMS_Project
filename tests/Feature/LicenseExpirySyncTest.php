<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\LicenseStatus;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Licenses\Support\LicenseEffectiveStatus;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicenseExpirySyncTest extends TestCase
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
        ]);
    }

    private function makeLicense(array $overrides = []): License
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-EX-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now(),
            'approved_at' => now(),
            'issued_at' => now(),
        ]);

        return License::query()->create(array_merge([
            'license_number' => 'LIC-EX-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(2)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'verification_token' => Str::random(48),
            'print_count' => 0,
        ], $overrides));
    }

    public function test_effective_status_detects_expired_without_mutating_row(): void
    {
        $license = $this->makeLicense();
        $this->assertSame(LicenseStatus::Active, $license->status);
        $this->assertSame(LicenseStatus::Expired, LicenseEffectiveStatus::resolve($license));
        $this->assertSame(LicenseStatus::Active, $license->fresh()->status);
    }

    public function test_sync_command_updates_and_is_idempotent(): void
    {
        $license = $this->makeLicense();

        Artisan::call('licenses:sync-expired');
        $this->assertSame(LicenseStatus::Expired, $license->fresh()->status);
        $this->assertDatabaseHas('license_status_histories', [
            'license_id' => $license->id,
            'action' => 'expired',
        ]);

        $count = \App\Models\LicenseStatusHistory::query()
            ->where('license_id', $license->id)
            ->where('action', 'expired')
            ->count();

        Artisan::call('licenses:sync-expired');
        $this->assertSame($count, \App\Models\LicenseStatusHistory::query()
            ->where('license_id', $license->id)
            ->where('action', 'expired')
            ->count());
    }

    public function test_backfill_verification_tokens_command(): void
    {
        $license = $this->makeLicense(['verification_token' => null]);
        Artisan::call('licenses:backfill-verification-tokens');
        $this->assertNotNull($license->fresh()->verification_token);
    }
}
