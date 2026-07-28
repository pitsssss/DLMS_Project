<?php

namespace App\Console\Commands;

use App\Modules\Licenses\Services\LicenseLifecycleService;
use Illuminate\Console\Command;

class SyncExpiredLicensesCommand extends Command
{
    protected $signature = 'licenses:sync-expired
                            {--chunk=200 : Number of licenses processed per chunk}';

    protected $description = 'Mark past-expiry active licenses as expired (idempotent)';

    public function handle(LicenseLifecycleService $lifecycle): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $result = $lifecycle->syncExpired($chunk);

        $this->info('Updated='.$result['updated']);

        return self::SUCCESS;
    }
}
