<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            PermissionsSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            TestTypesSeeder::class,
            RequiredDocumentsSeeder::class,
            FeesSeeder::class,
            FaqSeeder::class,
            AppointmentCentersSeeder::class,
            AppointmentSlotsSeeder::class,
            SuperAdminUserSeeder::class,
            DashboardEmployeesSeeder::class,
            AdminUserSeeder::class,
            EmployeeUserSeeder::class,
            CitizenUserSeeder::class,
            FullLifecycleSeeder::class,
            DashboardDocumentReviewDemoSeeder::class,
            DemoLicenseServiceTestingSeeder::class,
            DashboardCitizenLicensesFinesDemoSeeder::class,

        ]);

        if (app()->environment(['local', 'testing'])) {
            $this->call([
                LostReplacementTestCitizenSeeder::class,
                CommitteeDemoSeeder::class,
            ]);
        }
    }
}
