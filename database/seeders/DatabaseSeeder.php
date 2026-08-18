<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Canonical entry point for `php artisan db:seed` / `migrate:fresh --seed`.
 *
 * Production / staging: catalogs + bootstrap super-admin only.
 * Local / testing: also runs DevelopmentDemoSeeder (FullLifecycle, Fine Payment, …).
 *
 * Demo financial data (FLOW-*, PAY-CFP-*, committee fixtures, demo passwords)
 * must never seed automatically when APP_ENV=production.
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

        // 2) Development / QA demo kits — local & testing only
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevelopmentDemoSeeder::class);
        }
    }
}
