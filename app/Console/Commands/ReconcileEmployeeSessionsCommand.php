<?php

namespace App\Console\Commands;

use App\Enums\EmployeeSessionEndedReason;
use App\Models\EmployeeSession;
use App\Modules\Dashboard\Services\EmployeeSessions\EmployeeSessionStatusResolver;
use Illuminate\Console\Command;

class ReconcileEmployeeSessionsCommand extends Command
{
    protected $signature = 'employee-sessions:reconcile
                            {--chunk= : Chunk size}';

    protected $description = 'Reconcile expired or credential-missing employee Dashboard sessions';

    public function handle(EmployeeSessionStatusResolver $resolver): int
    {
        $chunk = (int) ($this->option('chunk') ?: config('employee_sessions.reconcile_chunk_size', 200));
        $updated = 0;
        $scanned = 0;

        EmployeeSession::query()
            ->whereNull('revoked_at')
            ->whereNull('logged_out_at')
            ->where(function ($q) {
                $q->whereNull('ended_reason')
                    ->orWhereNotIn('ended_reason', [
                        EmployeeSessionEndedReason::Expired->value,
                        EmployeeSessionEndedReason::CredentialMissing->value,
                        EmployeeSessionEndedReason::Revoked->value,
                        EmployeeSessionEndedReason::ExplicitLogout->value,
                    ]);
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($sessions) use ($resolver, &$updated, &$scanned) {
                foreach ($sessions as $session) {
                    $scanned++;
                    $session->load('personalAccessToken');
                    if ($resolver->reconcileEndedState($session)) {
                        $updated++;
                    }
                }
            });

        $this->info(__('messages.employee_sessions.commands.reconcile_done', [
            'scanned' => $scanned,
            'updated' => $updated,
        ]));

        return self::SUCCESS;
    }
}
