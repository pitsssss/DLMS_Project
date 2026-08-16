<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated Use FullLifecycleSeeder. Kept so older commands keep working
 * without truncating catalog tables.
 */
class LicenseApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(FullLifecycleSeeder::class);
    }
}
