<?php

namespace App\Console\Commands;

use App\Modules\Dashboard\Services\RbacBootstrapService;
use Illuminate\Console\Command;

class RbacAuditCommand extends Command
{
    protected $signature = 'rbac:audit';

    protected $description = 'Report RBAC inconsistencies (missing/obsolete permissions, document reviewer drift)';

    public function handle(RbacBootstrapService $rbac): int
    {
        $result = $rbac->audit();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
