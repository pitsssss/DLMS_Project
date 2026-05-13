<?php

namespace Database\Seeders;

use App\Models\LicenseType;
use Illuminate\Database\Seeder;

class LicenseTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Private License', 'code' => 'private', 'minimum_age' => 18, 'validity_years' => 5],
            ['name' => 'Public License', 'code' => 'public', 'minimum_age' => 21, 'validity_years' => 5],
            ['name' => 'Truck License', 'code' => 'truck', 'minimum_age' => 21, 'validity_years' => 5],
            ['name' => 'Bus License', 'code' => 'bus', 'minimum_age' => 21, 'validity_years' => 5],
        ];

        foreach ($types as $type) {
            LicenseType::firstOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'minimum_age' => $type['minimum_age'],
                    'validity_years' => $type['validity_years'],
                    'is_active' => true,
                ]
            );
        }
    }
}
