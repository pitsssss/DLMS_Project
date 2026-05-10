<?php

namespace Database\Seeders;

use App\Models\AppointmentSlot;
use App\Models\TestType;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AppointmentSlotsSeeder extends Seeder
{
    public function run(): void
    {
        $start = Carbon::today();

        foreach (TestType::orderBy('sequence_order')->get() as $testType) {
            for ($d = 0; $d < 14; $d++) {
                $date = (clone $start)->addDays($d)->toDateString();

                AppointmentSlot::firstOrCreate(
                    [
                        'test_type_id' => $testType->id,
                        'date' => $date,
                        'start_time' => '09:00:00',
                        'end_time' => '11:00:00',
                    ],
                    [
                        'capacity' => 10,
                        'booked_count' => 0,
                        'location' => 'Main Testing Center',
                        'is_active' => true,
                    ]
                );

                AppointmentSlot::firstOrCreate(
                    [
                        'test_type_id' => $testType->id,
                        'date' => $date,
                        'start_time' => '14:00:00',
                        'end_time' => '16:00:00',
                    ],
                    [
                        'capacity' => 10,
                        'booked_count' => 0,
                        'location' => 'Main Testing Center',
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
