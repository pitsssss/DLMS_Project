<?php

namespace Database\Seeders;

use App\Models\AppointmentCenter;
use App\Models\AppointmentSlot;
use App\Models\TestType;
use Illuminate\Database\Seeder;

class AppointmentSlotsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AppointmentCentersSeeder::class);

        $center = AppointmentCenter::query()->where('name', 'المركز الرئيسي')->firstOrFail();
        // Slot calendar follows business timezone (not APP_TIMEZONE/UTC).
        $start = app(\App\Support\BusinessClock::class)->now()->startOfDay();

        foreach (TestType::orderBy('sequence_order')->get() as $testType) {
            for ($d = 0; $d < 14; $d++) {
                $date = $start->copy()->addDays($d)->toDateString();

                AppointmentSlot::updateOrCreate(
                    [
                        'test_type_id' => $testType->id,
                        'date' => $date,
                        'start_time' => '09:00:00',
                        'end_time' => '11:00:00',
                    ],
                    [
                        'appointment_center_id' => $center->id,
                        'capacity' => 10,
                        'booked_count' => 0,
                        'location' => $center->name,
                        'is_active' => true,
                    ]
                );

                AppointmentSlot::updateOrCreate(
                    [
                        'test_type_id' => $testType->id,
                        'date' => $date,
                        'start_time' => '14:00:00',
                        'end_time' => '16:00:00',
                    ],
                    [
                        'appointment_center_id' => $center->id,
                        'capacity' => 10,
                        'booked_count' => 0,
                        'location' => $center->name,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
