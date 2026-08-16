<?php

namespace Database\Seeders;

use Database\Seeders\Support\CommitteeDemoKit;
use Illuminate\Database\Seeder;

class CommitteeTestResultSeeder extends Seeder
{
    public function run(): void
    {
        $kit = new CommitteeDemoKit($this, $this->command);
        $kit->guardEnvironment();
        $kit->ensureCatalog();
        $employees = $kit->ensureEmployees();
        $examiner = $employees['examiner'];

        $scenarios = [
            ['A — practical waiting (record passed → approved)', $kit->seedScenarioA($examiner)],
            ['B — vision waiting (record failed)', $kit->seedScenarioB($examiner)],
            ['C — vision waiting (record no_show)', $kit->seedScenarioC($examiner)],
            ['E — theory waiting (vision passed)', $kit->seedScenarioE($examiner)],
            ['F — retest after 1 fail (previous_attempts=1)', $kit->seedScenarioF($examiner)],
            ['G — last attempt (2 prior fails/no-shows)', $kit->seedScenarioG($examiner)],
            ['H — completed+passed history + waiting theory', $kit->seedScenarioH($examiner)],
            ['I — completed+failed history + waiting retest', $kit->seedScenarioI($examiner)],
            ['J — cancelled history + waiting vision', $kit->seedScenarioJ($examiner)],
            ['K — no_show appointment history + waiting practical', $kit->seedScenarioK($examiner)],
            ['L — booked but app approved (can_record_result=false)', $kit->seedScenarioL($examiner)],
        ];

        $kit->info('Committee test-result scenarios ready (waiting_result + filters).');
        $kit->info('Examiner: '.CommitteeDemoKit::EXAMINER_EMAIL.' / '.CommitteeDemoKit::PASSWORD);
        $kit->info('Filters: status=waiting_result|booked|completed|cancelled|no_show  test_type_code=vision|theory|practical');

        foreach ($scenarios as [$label, $application]) {
            $kit->reportScenario(
                'Scenario '.$label,
                $application,
                $kit->waitingAppointmentId($application),
                '/dashboard/test-appointments'
            );
        }
    }
}
