<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Local / testing development & demo dataset aggregator.
 *
 * Invoked from DatabaseSeeder when APP_ENV is local or testing.
 * Never call this in production.
 *
 * Includes FullLifecycle (FLOW-*), dashboard demos, CommitteeDemo,
 * and Citizen Fine Payment (CFP / PAY-CFP-*) fixtures.
 *
 * See docs/DEVELOPMENT_DATABASE_SEEDING.md
 */
class DevelopmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DevelopmentDemoSeeder skipped (not local/testing).');

            return;
        }

        $this->call([
            // Baseline accounts used by dashboard / citizen demos
            DashboardEmployeesSeeder::class,
            AdminUserSeeder::class,
            EmployeeUserSeeder::class,
            CitizenUserSeeder::class,

            // Project-wide lifecycle coverage (FLOW-* applications, licenses, payments, …)
            FullLifecycleSeeder::class,

            // Additional focused demos (idempotent / upsert where designed)
            DashboardDocumentReviewDemoSeeder::class,
            DemoLicenseServiceTestingSeeder::class,
            DashboardCitizenLicensesFinesDemoSeeder::class,
            LostReplacementTestCitizenSeeder::class,

            // Committee queue fixtures (dashboard test-results / issuance demos)
            CommitteeDemoSeeder::class,

            // Citizen Fine Payment + My Payments scenarios (PAY-CFP-*, [CFP-*])
            CitizenFinePaymentDemoSeeder::class,
        ]);
    }
}
