<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\LicenseStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoLicenseServiceTestingSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->where('name', 'citizen')->firstOrFail();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $newLicenseService = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $demos = [
            [
                'email' => 'renew.citizen@example.com',
                'phone' => '0977001001',
                'name' => 'مواطن تجديد الرخصة',
                'national_id' => 'DEMO-RENEW-0001',
                'application_number' => 'APP-DEMO-RENEW-ORIG',
                'license_number' => 'LIC-RENEW-2026-0001',
                'license_status' => LicenseStatus::Active,
                'issue_date' => now()->subYears(9)->toDateString(),
                'expiry_date' => now()->addDays(25)->toDateString(),
            ],
            [
                'email' => 'lost.citizen@example.com',
                'phone' => '0977001002',
                'name' => 'مواطن بدل فاقد',
                'national_id' => 'DEMO-LOST-0001',
                'application_number' => 'APP-DEMO-LOST-ORIG',
                'license_number' => 'LIC-LOST-2026-0001',
                'license_status' => LicenseStatus::Active,
                'issue_date' => now()->subYears(3)->toDateString(),
                'expiry_date' => now()->addYears(2)->toDateString(),
            ],
            [
                'email' => 'damaged.citizen@example.com',
                'phone' => '0977001003',
                'name' => 'مواطن بدل تالف',
                'national_id' => 'DEMO-DAMAGED-0001',
                'application_number' => 'APP-DEMO-DAMAGED-ORIG',
                'license_number' => 'LIC-DAMAGED-2026-0001',
                'license_status' => LicenseStatus::Active,
                'issue_date' => now()->subYears(2)->toDateString(),
                'expiry_date' => now()->addYears(3)->toDateString(),
            ],
        ];

        foreach ($demos as $demo) {
            $this->seedDemoCitizenWithLicense($role, $licenseType, $newLicenseService, $demo);
        }
    }

    /**
     * @param  array{
     *   email: string,
     *   phone: string,
     *   name: string,
     *   national_id: string,
     *   application_number: string,
     *   license_number: string,
     *   license_status: LicenseStatus,
     *   issue_date: string,
     *   expiry_date: string
     * }  $demo
     */
    private function seedDemoCitizenWithLicense(
        Role $role,
        LicenseType $licenseType,
        ServiceType $newLicenseService,
        array $demo,
    ): void {
        $issuedAt = now()->subYears(1);

        $citizen = User::updateOrCreate(
            ['email' => $demo['email']],
            [
                'name' => $demo['name'],
                'phone' => $demo['phone'],
                'national_id' => $demo['national_id'],
                'birth_date' => '1990-05-15',
                'governorate' => 'دمشق',
                'address' => 'دمشق — بيانات تجريبية لاختبار خدمات الرخص',
                'password' => Hash::make('password123'),
                'role_id' => $role->id,
                'user_type' => UserType::Citizen,
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'profile_submitted_at' => $issuedAt,
                'profile_reviewed_at' => $issuedAt,
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );

        $originalApplication = LicenseApplication::updateOrCreate(
            ['application_number' => $demo['application_number']],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $newLicenseService->id,
                'related_license_id' => null,
                'status' => ApplicationStatus::LicenseIssued,
                'current_test_type_id' => null,
                'rejection_reason' => null,
                'submitted_at' => $issuedAt,
                'approved_at' => $issuedAt,
                'issued_at' => $issuedAt,
            ]
        );

        License::updateOrCreate(
            ['license_number' => $demo['license_number']],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'application_id' => $originalApplication->id,
                'status' => $demo['license_status'],
                'issue_date' => $demo['issue_date'],
                'expiry_date' => $demo['expiry_date'],
            ]
        );
    }
}
