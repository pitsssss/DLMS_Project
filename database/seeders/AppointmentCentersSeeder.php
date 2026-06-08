<?php

namespace Database\Seeders;

use App\Models\AppointmentCenter;
use Illuminate\Database\Seeder;

class AppointmentCentersSeeder extends Seeder
{
    public function run(): void
    {
        AppointmentCenter::updateOrCreate(
            ['name' => 'المركز الرئيسي'],
            [
                'address' => 'شارع الملك فيصل، الحي الحكومي',
                'latitude' => 33.5138,
                'longitude' => 36.2765,
                'is_active' => true,
            ]
        );
    }
}
