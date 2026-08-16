<?php

namespace Database\Seeders;

use Database\Seeders\Support\CommitteeDemoKit;
use Illuminate\Database\Seeder;

/**
 * Seeds diverse dashboard demo data for:
 * - Test results queue: GET /api/dashboard/test-appointments
 * - License issuance queue: GET /api/dashboard/license-issuance/applications
 *
 * Local / testing only.
 *
 *   php artisan db:seed --class=CommitteeDemoSeeder
 */
class CommitteeDemoSeeder extends Seeder
{
    public function run(): void
    {
        $kit = new CommitteeDemoKit($this, $this->command);
        $kit->guardEnvironment();

        $this->call([
            CommitteeTestResultSeeder::class,
            CommitteeLicenseIssuanceSeeder::class,
        ]);

        $kit->info('────────────────────────────────────────');
        $kit->info('All committee demo scenarios are ready.');
        $kit->info('Password (local/testing only): '.CommitteeDemoKit::PASSWORD);
        $kit->info('Examiner: '.CommitteeDemoKit::EXAMINER_EMAIL);
        $kit->info('Issuer:   '.CommitteeDemoKit::ISSUER_EMAIL);
        $kit->info('Pages: /dashboard/test-appointments  |  /dashboard/license-issuance');
    }
}
