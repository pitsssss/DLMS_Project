<?php

namespace Database\Seeders;

use Database\Seeders\Support\CommitteeDemoKit;
use Illuminate\Database\Seeder;

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

        $kit->info('All committee demo scenarios are ready.');
        $kit->info('Password (local/testing only): '.CommitteeDemoKit::PASSWORD);
    }
}
