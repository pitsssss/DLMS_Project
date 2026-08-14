<?php

namespace Database\Seeders;

use Database\Seeders\Support\CommitteeDemoKit;
use Illuminate\Database\Seeder;

class CommitteeLicenseIssuanceSeeder extends Seeder
{
    public function run(): void
    {
        $kit = new CommitteeDemoKit($this, $this->command);
        $kit->guardEnvironment();
        $kit->ensureCatalog();
        $employees = $kit->ensureEmployees();

        $scenarioD = $kit->seedScenarioD($employees['examiner']);

        $kit->info('Committee license-issuance fallback ready.');
        $kit->info('Issuer: '.CommitteeDemoKit::ISSUER_EMAIL);
        $kit->reportScenario(
            'Scenario D — ready to issue',
            $scenarioD,
            null,
            '/dashboard/license-issuance'
        );
    }
}
