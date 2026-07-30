<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Modules\Dashboard\Services\RbacBootstrapService;
use App\Modules\Dashboard\Support\PermissionRegistry;
use Illuminate\Database\Seeder;

/**
 * Non-destructive permission catalog seeder.
 * Creates missing permissions and bootstraps missing role metadata.
 * Does NOT sync/overwrite existing role permission pivots (use rbac:repair-* for corrections).
 */
class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionRegistry::permissionNames() as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // Ensure system role rows/metadata exist without wiping Dashboard-managed pivots.
        app(RbacBootstrapService::class)->bootstrap(apply: true);
    }
}
