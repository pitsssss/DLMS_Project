<?php

namespace Database\Seeders;

use Database\Seeders\Support\CitizenFinePaymentDemoKit;
use Illuminate\Database\Seeder;

/**
 * Deterministic Fine Payment + My Payments demo kit (local / testing only).
 *
 *   php artisan db:seed --class=CitizenFinePaymentDemoSeeder
 *
 * See docs/CITIZEN_FINE_PAYMENT_DEMO_SEEDER.md
 */
class CitizenFinePaymentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $kit = new CitizenFinePaymentDemoKit($this, $this->command);
        $kit->guardEnvironment();
        $kit->ensureCatalog();
        $kit->seedAll();
        $kit->printSummary();
    }
}
