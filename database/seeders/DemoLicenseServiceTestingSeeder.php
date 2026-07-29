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
                'name' => 'وسام فادي الخطيب',
                'national_id' => '01030011111',
                'birth_date' => '1987-03-20',
                'governorate' => 'دمشق',
                'address' => 'دمشق — برزة — شارع الثورة — بناء 6',
                'application_number' => 'APP-DEMO-RENEW-ORIG',
                'license_number' => 'LIC-RENEW-2026-0001',
                'license_status' => LicenseStatus::Active,
                'issue_date' => now()->subYears(9)->toDateString(),
                'expiry_date' => now()->addDays(25)->toDateString(),
            ],
            [
                'email' => 'lost.citizen@example.com',
                'phone' => '0977001002',
                'name' => "رامي كمال عبود",
                'national_id' => '01030022222',
                'birth_date' => '1993-09-14',
                'governorate' => 'حلب',
                'address' => "حلب — صالحين — شارع الجامعة — عمارة 4",
                'application_number' => 'APP-DEMO-LOST-ORIG',
                'license_number' => 'LIC-LOST-2026-0001',
                'license_status' => LicenseStatus::Active,
                'issue_date' => now()->subYears(3)->toDateString(),
                'expiry_date' => now()->addYears(2)->toDateString(),
            ],
            [
                'email' => 'damaged.citizen@example.com',
                'phone' => '0977001003',
                'name' => "لينا جورج حنا",
                'national_id' => '01030033333',
                'birth_date' => '1996-01-08',
                'governorate' => 'حمص',
                'address' => "حمص — الغوتة — شارع الحميدية — طابق 5",
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
     *   birth_date: string,
     *   governorate: string,
     *   address: string,
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
                'birth_date' => $demo['birth_date'],
                'governorate' => $demo['governorate'],
                'address' => $demo['address'],
                'language' => 'ar',
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
