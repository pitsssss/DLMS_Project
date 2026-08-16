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
        $examiner = $employees['examiner'];

        $ready = [
            ['D — new private READY', $kit->seedScenarioD($examiner)],
            ['M — new public READY', $kit->seedScenarioM($examiner)],
            ['U — new truck READY', $kit->seedScenarioU($examiner)],
            ['N — renew READY', $kit->seedScenarioN($examiner)],
            ['O — lost replacement READY', $kit->seedScenarioO($examiner)],
            ['P — damaged replacement READY', $kit->seedScenarioP($examiner)],
        ];

        $blocked = [
            ['Q — approved unpaid (payment_required)', $kit->seedScenarioQ($examiner)],
            ['R — approved unpaid fine (unpaid_fines_issue)', $kit->seedScenarioR($examiner)],
            ['S — already issued (already_issued)', $kit->seedScenarioS($examiner)],
            ['T — missing docs (documents_required)', $kit->seedScenarioT($examiner)],
        ];

        $kit->info('Committee license-issuance scenarios ready.');
        $kit->info('Issuer: '.CommitteeDemoKit::ISSUER_EMAIL.' / '.CommitteeDemoKit::PASSWORD);
        $kit->info('Ready queue → GET /api/dashboard/license-issuance/applications');
        $kit->info('Blocked details → GET /api/dashboard/license-issuance/applications/{id}');

        foreach ($ready as [$label, $application]) {
            $kit->reportScenario(
                'Scenario '.$label,
                $application,
                null,
                '/dashboard/license-issuance'
            );
        }

        foreach ($blocked as [$label, $application]) {
            $kit->reportScenario(
                'Scenario '.$label.' [details only]',
                $application,
                null,
                '/dashboard/license-issuance/applications/'.$application->id
            );
        }
    }
}
