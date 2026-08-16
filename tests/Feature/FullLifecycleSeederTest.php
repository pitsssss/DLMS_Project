<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProfileStatus;
use App\Enums\TestResultStatus;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\ContactMessage;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\User;
use Database\Seeders\AppointmentCentersSeeder;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\DashboardEmployeesSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\FullLifecycleSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FullLifecycleSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedLifecycle(): void
    {
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            TestTypesSeeder::class,
            RequiredDocumentsSeeder::class,
            FeesSeeder::class,
            AppointmentCentersSeeder::class,
            AppointmentSlotsSeeder::class,
            DashboardEmployeesSeeder::class,
            FullLifecycleSeeder::class,
        ]);
    }

    public function test_full_lifecycle_covers_every_feature_status_with_real_related_records(): void
    {
        $this->seedLifecycle();

        foreach (ApplicationStatus::cases() as $status) {
            $this->assertTrue(
                LicenseApplication::query()->where('status', $status)->where('application_number', 'like', 'FLOW-%')->exists(),
                "Missing FLOW application in status [{$status->value}]"
            );
        }

        $issued = LicenseApplication::query()
            ->where('application_number', 'FLOW-NL-PRV-ISS-01')
            ->firstOrFail();

        $this->assertSame(ApplicationStatus::LicenseIssued, $issued->status);
        $this->assertNotNull($issued->submitted_at);
        $this->assertNotNull($issued->approved_at);
        $this->assertNotNull($issued->issued_at);
        $this->assertGreaterThan(3, ApplicationStatusHistory::query()->where('application_id', $issued->id)->count());
        $this->assertGreaterThanOrEqual(3, ApplicationDocument::query()
            ->where('application_id', $issued->id)
            ->where('status', DocumentStatus::Approved)
            ->count());
        $this->assertTrue(Payment::query()
            ->where('application_id', $issued->id)
            ->where('status', PaymentStatus::Completed)
            ->exists());
        $this->assertSame(3, TestResult::query()
            ->where('application_id', $issued->id)
            ->where('result', TestResultStatus::Passed)
            ->count());

        $license = License::query()->where('application_id', $issued->id)->firstOrFail();
        $this->assertNotEmpty($license->verification_token);
        $this->assertNotNull($license->issued_by);

        $renewal = LicenseApplication::query()
            ->where('application_number', 'like', 'FLOW-RN-%-ISS-%')
            ->where('status', ApplicationStatus::LicenseIssued)
            ->firstOrFail();
        $this->assertNotNull($renewal->related_license_id);
        $newLicense = License::query()->where('application_id', $renewal->id)->firstOrFail();
        $this->assertSame($renewal->related_license_id, $newLicense->previous_license_id);
        $this->assertSame(LicenseStatus::Renewed, License::query()->findOrFail($newLicense->previous_license_id)->status);

        foreach ([LicenseStatus::Active, LicenseStatus::Expired, LicenseStatus::Blocked, LicenseStatus::Suspended, LicenseStatus::Renewed, LicenseStatus::Inactive] as $status) {
            $this->assertTrue(License::query()->where('status', $status)->exists(), "Missing license [{$status->value}]");
        }
        foreach (FineStatus::cases() as $status) {
            $this->assertTrue(Fine::query()->where('status', $status)->exists(), "Missing fine [{$status->value}]");
        }
        foreach ([PaymentStatus::Pending, PaymentStatus::Completed, PaymentStatus::Failed, PaymentStatus::UnderVerification] as $status) {
            $this->assertTrue(Payment::query()->where('status', $status)->exists(), "Missing payment [{$status->value}]");
        }
        foreach (AppointmentStatus::cases() as $status) {
            $this->assertTrue(TestAppointment::query()->where('status', $status)->exists(), "Missing appointment [{$status->value}]");
        }
        foreach (ProfileStatus::cases() as $status) {
            $this->assertTrue(
                User::query()->where('user_type', 'citizen')->where('profile_status', $status)->exists(),
                "Missing profile [{$status->value}]"
            );
        }
        foreach (['new', 'read', 'in_progress', 'resolved', 'closed'] as $status) {
            $this->assertTrue(ContactMessage::query()->where('status', $status)->exists(), "Missing contact [{$status}]");
        }

        $this->assertGreaterThanOrEqual(10, LicenseApplication::query()->where('status', ApplicationStatus::DocumentsUnderReview)->count());
        $this->assertGreaterThanOrEqual(8, LicenseApplication::query()->where('status', ApplicationStatus::Approved)->count());
        $this->assertGreaterThanOrEqual(10, LicenseApplication::query()->where('status', ApplicationStatus::LicenseIssued)->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedLifecycle();

        $applications = LicenseApplication::query()->where('application_number', 'like', 'FLOW-%')->count();
        $licenses = License::query()->count();

        $this->seed(FullLifecycleSeeder::class);

        $this->assertSame(
            $applications,
            LicenseApplication::query()->where('application_number', 'like', 'FLOW-%')->count()
        );
        $this->assertSame($licenses, License::query()->count());
    }
}
