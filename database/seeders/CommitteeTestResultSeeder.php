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

        $scenarioA = $kit->seedScenarioA($examiner);
        $scenarioB = $kit->seedScenarioB($examiner);
        $scenarioC = $kit->seedScenarioC($examiner);

        $kit->info('Committee test-result scenarios ready.');
        $kit->info('Examiner: '.CommitteeDemoKit::EXAMINER_EMAIL);
        $kit->reportScenario(
            'Scenario A — final practical (record passed)',
            $scenarioA,
            $kit->waitingAppointmentId($scenarioA),
            '/dashboard/test-appointments'
        );
        $kit->reportScenario(
            'Scenario B — failed path (record failed)',
            $scenarioB,
            $kit->waitingAppointmentId($scenarioB),
            '/dashboard/test-appointments'
        );
        $kit->reportScenario(
            'Scenario C — no-show path (record no_show)',
            $scenarioC,
            $kit->waitingAppointmentId($scenarioC),
            '/dashboard/test-appointments'
        );
    }
}
