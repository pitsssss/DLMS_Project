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
            ['email' => 'citizen@example.com'],
            [
                'name' => 'Sample Citizen',
                'phone' => '0977777777',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'user_type' => UserType::Citizen,
                'profile_completed' => false,
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => null,
            ]
        );
    }
}
