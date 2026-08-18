<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoSeeding;
use Illuminate\Database\Seeder;

/**
 * Development & hosted-demo dataset aggregator.
 *
 * Invoked from DatabaseSeeder when DemoSeeding::isAllowed()
 * (local/testing, or DEMO_SEEDING_ENABLED=true).
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
        if (! DemoSeeding::isAllowed()) {
            $this->command?->warn('DevelopmentDemoSeeder skipped (demo seeding not allowed).');

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
