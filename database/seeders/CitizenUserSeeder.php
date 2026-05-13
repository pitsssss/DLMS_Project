<?php

namespace Database\Seeders;

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

        User::firstOrCreate(
            ['phone' => '0977777777'],
            [
                'name' => 'Sample Citizen',
                'email' => 'citizen@example.com',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'user_type' => UserType::Citizen,
                'profile_completed' => false,
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );
    }
}
