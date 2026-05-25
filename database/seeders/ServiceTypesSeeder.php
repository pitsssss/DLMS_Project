<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypesSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'إصدار رخصة جديدة', 'code' => 'new_license'],
            ['name' => 'تجديد رخصة', 'code' => 'renew_license'],
            ['name' => 'بدل فاقد', 'code' => 'lost_replacement'],
            ['name' => 'بدل تالف', 'code' => 'damaged_replacement'],
            ['name' => 'فك حظر رخصة', 'code' => 'license_unblock'],
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
