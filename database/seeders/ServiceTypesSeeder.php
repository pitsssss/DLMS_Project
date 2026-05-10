<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypesSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'New License', 'code' => 'new_license'],
            ['name' => 'Renew License', 'code' => 'renew_license'],
            ['name' => 'Lost Replacement', 'code' => 'lost_replacement'],
            ['name' => 'Damaged Replacement', 'code' => 'damaged_replacement'],
            ['name' => 'License Unblock', 'code' => 'license_unblock'],
        ];

        foreach ($services as $service) {
            ServiceType::firstOrCreate(
                ['code' => $service['code']],
                [
                    'name' => $service['name'],
                    'description' => null,
                    'is_active' => true,
                ]
            );
        }
    }
}
