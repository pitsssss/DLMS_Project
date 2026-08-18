<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoSeeding;
use Illuminate\Database\Seeder;

/**
 * Canonical entry point for `php artisan db:seed` / `migrate:fresh --seed`.
 *
 * Always: catalogs + bootstrap super-admin.
 * Demo kits: local/testing, OR explicit DEMO_SEEDING_ENABLED=true (hosted demo/QA).
 *
 * Real production must keep DEMO_SEEDING_ENABLED=false.
 *
 * See docs/DEVELOPMENT_DATABASE_SEEDING.md
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Production-safe catalogs & system bootstrap (all environments)
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
        ]);

        // 2) Development / hosted-demo kits (never URL-based)
        if (DemoSeeding::isAllowed()) {
            $this->call(DevelopmentDemoSeeder::class);
        }
    }
}
