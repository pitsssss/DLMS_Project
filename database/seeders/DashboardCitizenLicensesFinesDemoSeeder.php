<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DashboardCitizenLicensesFinesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $citizen = $this->demoCitizen();
        $newLicense = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $privateLicense = $this->seedLicense(
            citizen: $citizen,
            licenseType: LicenseType::query()->where('code', 'private')->firstOrFail(),
            serviceType: $newLicense,
            applicationNumber: 'APP-DASH-CITIZEN-LIC-001',
            licenseNumber: 'LIC-DASH-CITIZEN-001',
            status: LicenseStatus::Active,
            issueDate: now()->subYears(2)->toDateString(),
            expiryDate: now()->addYears(3)->toDateString()
        );

        $publicLicense = $this->seedLicense(
            citizen: $citizen,
            licenseType: LicenseType::query()->where('code', 'public')->firstOrFail(),
            serviceType: $newLicense,
            applicationNumber: 'APP-DASH-CITIZEN-LIC-002',
            licenseNumber: 'LIC-DASH-CITIZEN-002',
            status: LicenseStatus::Expired,
            issueDate: now()->subYears(7)->toDateString(),
            expiryDate: now()->subYears(2)->toDateString()
        );

        $truckLicense = $this->seedLicense(
            citizen: $citizen,
            licenseType: LicenseType::query()->where('code', 'truck')->firstOrFail(),
            serviceType: $newLicense,
            applicationNumber: 'APP-DASH-CITIZEN-LIC-003',
            licenseNumber: 'LIC-DASH-CITIZEN-003',
            status: LicenseStatus::Blocked,
            issueDate: now()->subYear()->toDateString(),
            expiryDate: now()->addYears(4)->toDateString()
        );

        $this->seedFine(
            citizen: $citizen,
            license: $privateLicense,
            amount: 75000,
            reason: 'مخالفة سرعة زائدة على الطريق العام.',
            status: FineStatus::Unpaid
        );

        $this->seedFine(
            citizen: $citizen,
            license: $publicLicense,
            amount: 50000,
            reason: 'تأخير تجديد الرخصة بعد انتهاء الصلاحية.',
            status: FineStatus::Paid,
            paidAt: now()->subDays(5)
        );

        $this->seedFine(
            citizen: $citizen,
            license: $truckLicense,
            amount: 120000,
            reason: 'قيادة مركبة برخصة عليها حظر إداري.',
            status: FineStatus::Cancelled
        );
    }

    private function demoCitizen(): User
    {
        $role = Role::query()->where('name', 'citizen')->firstOrFail();

        return User::query()->updateOrCreate(
            ['email' => 'dashboard.citizen.history@example.com'],
            [
                'name' => 'مواطن تجريبي للرخص والغرامات',
                'phone' => '0992000001',
                'national_id' => 'DASH-CIT-HISTORY-001',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'user_type' => UserType::Citizen,
                'birth_date' => '1990-04-15',
                'governorate' => 'Damascus',
                'address' => 'Demo citizen for dashboard licenses and fines endpoints',
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'profile_submitted_at' => now()->subDays(30),
                'profile_reviewed_at' => now()->subDays(29),
                'is_active' => true,
                'email_verified_at' => now()->subDays(30),
                'phone_verified_at' => now()->subDays(30),
            ]
        );
    }

    private function seedLicense(
        User $citizen,
        LicenseType $licenseType,
        ServiceType $serviceType,
        string $applicationNumber,
        string $licenseNumber,
        LicenseStatus $status,
        string $issueDate,
        string $expiryDate,
    ): License {
        $issuedAt = now()->subYears(2);

        $application = LicenseApplication::query()->updateOrCreate(
            ['application_number' => $applicationNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'status' => ApplicationStatus::LicenseIssued,
                'current_test_type_id' => null,
                'rejection_reason' => null,
                'submitted_at' => $issuedAt,
                'approved_at' => $issuedAt,
                'issued_at' => $issuedAt,
            ]
        );

        return License::query()->updateOrCreate(
            ['license_number' => $licenseNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'application_id' => $application->id,
                'status' => $status,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
            ]
        );
    }

    private function seedFine(
        User $citizen,
        License $license,
        int $amount,
        string $reason,
        FineStatus $status,
        mixed $paidAt = null,
    ): void {
        Fine::query()->updateOrCreate(
            [
                'citizen_id' => $citizen->id,
                'license_id' => $license->id,
                'reason' => $reason,
            ],
            [
                'amount' => $amount,
                'status' => $status,
                'paid_at' => $paidAt,
            ]
        );
    }
}
