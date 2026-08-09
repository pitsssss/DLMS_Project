<?php

namespace Tests\Feature;

use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Fines\Services\FineService;
use App\Modules\Licenses\Services\LicenseLifecycleService;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationLifecycleExtrasTest extends TestCase
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

    public function test_fine_cancelled_notifies_once(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'en',
        ]);
        $admin = User::factory()->dashboardAdmin('admin')->create();
        $fines = app(FineService::class);
        $fine = $fines->create($admin, $citizen->id, 1000, 'Parking');

        $fines->update($admin, $fine->id, ['status' => FineStatus::Cancelled->value]);
        $fines->update($admin, $fine->id, ['status' => FineStatus::Cancelled->value]);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::FineCancelled->value)
                ->count()
        );
        $note = Notification::query()->where('type', NotificationType::FineCancelled->value)->firstOrFail();
        $this->assertSame(Lang::get('messages.notifications.fine_cancelled_title', [], 'en'), $note->title);
    }

    public function test_license_expired_notifies_once(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'ar',
        ]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = \App\Models\ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = \App\Models\LicenseApplication::query()->create([
            'application_number' => 'APP-EXP-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => \App\Enums\ApplicationStatus::LicenseIssued,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now()->subYears(11),
            'approved_at' => now()->subYears(11),
            'issued_at' => now()->subYears(11),
        ]);

        $license = License::query()->create([
            'license_number' => 'LIC-N2-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(11)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'verification_token' => Str::random(48),
        ]);

        $lifecycle = app(LicenseLifecycleService::class);
        $this->assertTrue($lifecycle->expireIfNeeded($license));
        $this->assertFalse($lifecycle->expireIfNeeded($license->fresh()));

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::LicenseExpired->value)
                ->count()
        );
        $note = Notification::query()->where('type', NotificationType::LicenseExpired->value)->firstOrFail();
        $this->assertSame(Lang::get('messages.notifications.license_expired_title', [], 'ar'), $note->title);
        $this->assertStringContainsString($license->license_number, $note->body);
    }
}
