<?php

namespace App\Console\Commands;

use App\Modules\Dashboard\Services\VisitorAccountProvisioner;
use Illuminate\Console\Command;

class ProvisionVisitorAccountCommand extends Command
{
    protected $signature = 'visitor:provision {--dry-run : Report the result without writing}';

    protected $description = 'Create or update the single read-only visitor demo account without touching other users';

    public function handle(VisitorAccountProvisioner $provisioner): int
    {
        $result = $provisioner->provision(! $this->option('dry-run'));

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
