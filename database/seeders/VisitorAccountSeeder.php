<?php

namespace Database\Seeders;

use App\Modules\Dashboard\Services\VisitorAccountProvisioner;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Explicit visitor provisioning. Not called from DatabaseSeeder.
 * No-ops when DEMO_VISITOR_ENABLED is false.
 */
class VisitorAccountSeeder extends Seeder
{
    public function run(): void
    {
        $result = app(VisitorAccountProvisioner::class)->provision(true);

        if (($result['status'] ?? '') === 'disabled') {
            $this->command?->warn((string) $result['message']);

            return;
        }

        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($result['message'] ?? 'Visitor provisioning failed.'));
        }

        $this->command?->info((string) $result['message']);
    }
}
