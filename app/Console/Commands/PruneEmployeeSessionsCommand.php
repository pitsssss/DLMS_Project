<?php

namespace App\Console\Commands;

use App\Models\EmployeeSession;
use Illuminate\Console\Command;

class PruneEmployeeSessionsCommand extends Command
{
    protected $signature = 'employee-sessions:prune
                            {--dry-run : Report eligible rows without deleting (default behavior unless --apply)}
                            {--apply : Actually delete eligible ended sessions}
                            {--chunk= : Chunk size}
                            {--days= : Retention days override}';

    protected $description = 'Prune ended employee session history older than the retention policy';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $days = (int) ($this->option('days') ?: config('employee_sessions.retention_days', 180));
        $chunk = (int) ($this->option('chunk') ?: config('employee_sessions.prune_chunk_size', 200));
        $cutoff = now()->subDays(max(1, $days));

        $base = EmployeeSession::query()
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($ended) use ($cutoff) {
                    $ended->whereNotNull('revoked_at')->where('revoked_at', '<', $cutoff);
                })->orWhere(function ($ended) use ($cutoff) {
                    $ended->whereNotNull('logged_out_at')->where('logged_out_at', '<', $cutoff);
                })->orWhere(function ($ended) use ($cutoff) {
                    $ended->whereNotNull('expires_at')
                        ->where('expires_at', '<', $cutoff)
                        ->whereNull('revoked_at')
                        ->whereNull('logged_out_at')
                        ->where(function ($cred) {
                            $cred->whereNull('personal_access_token_id')
                                ->orWhereNotNull('ended_reason');
                        });
                });
            })
            // Never prune open sessions with a live credential and no end markers.
            ->where(function ($q) {
                $q->whereNotNull('revoked_at')
                    ->orWhereNotNull('logged_out_at')
                    ->orWhere(function ($expired) {
                        $expired->whereNull('personal_access_token_id')
                            ->whereNotNull('ended_reason');
                    })
                    ->orWhere(function ($expired) {
                        $expired->whereNotNull('expires_at')
                            ->where('expires_at', '<', now())
                            ->whereNull('personal_access_token_id');
                    });
            });

        $eligible = (clone $base)->count();

        if (! $apply) {
            $this->info(__('messages.employee_sessions.commands.prune_dry_run', [
                'count' => $eligible,
                'days' => $days,
            ]));

            return self::SUCCESS;
        }

        $deleted = 0;
        (clone $base)->orderBy('id')->chunkById($chunk, function ($sessions) use (&$deleted) {
            foreach ($sessions as $session) {
                // Extra safety: never delete if still looks open with a token.
                if ($session->personal_access_token_id !== null
                    && $session->revoked_at === null
                    && $session->logged_out_at === null
                    && ($session->expires_at === null || $session->expires_at->isFuture())
                ) {
                    continue;
                }
                $session->delete();
                $deleted++;
            }
        });

        $this->info(__('messages.employee_sessions.commands.prune_applied', [
            'deleted' => $deleted,
            'days' => $days,
        ]));

        return self::SUCCESS;
    }
}
