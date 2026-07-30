<?php

namespace App\Console\Commands;

use App\Modules\Dashboard\Services\RbacBootstrapService;
use Illuminate\Console\Command;

class RbacBootstrapCommand extends Command
{
    protected $signature = 'rbac:bootstrap {--dry-run : Report changes without writing}';

    protected $description = 'Idempotently create missing RBAC permissions/roles/metadata without overwriting existing role pivots';

    public function handle(RbacBootstrapService $rbac): int
    {
        $apply = ! $this->option('dry-run');
        $result = $rbac->bootstrap($apply);

        $this->info($apply ? 'RBAC bootstrap applied.' : 'RBAC bootstrap dry-run (no writes).');
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
