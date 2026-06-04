<?php

namespace Database\Seeders;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CitizenUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('name', 'citizen')->firstOrFail();

        $citizens = [
            [
                'email' => 'citizen@example.com',
                'phone' => '0977777777',
                'name' => 'مواطن تجريبي',
                'password' => 'password',
            ],
            [
                'email' => 'petertoss2004@gmail.com',
                'phone' => '0930673130',
                'name' => 'Peter Toss',
                'password' => 'password123',
            ],
        ];

        foreach ($citizens as $citizen) {
            User::updateOrCreate(
                ['email' => $citizen['email']],
                [
                    'name' => $citizen['name'],
                    'phone' => $citizen['phone'],
                    'password' => Hash::make($citizen['password']),
                    'role_id' => $role->id,
                    'user_type' => UserType::Citizen,
                    'profile_completed' => true,
                    'profile_status' => ProfileStatus::Approved,
                    'profile_submitted_at' => now(),
                    'profile_reviewed_at' => now(),
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ]
            );
        }
    }
}
