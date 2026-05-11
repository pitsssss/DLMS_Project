<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('name', 'employee')->firstOrFail();

        User::firstOrCreate(
            ['email' => 'employee@example.com'],
            [
                'name' => 'Sample Employee',
                'phone' => '0988888888',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'user_type' => UserType::Employee,
                'profile_completed' => true,
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => null,
            ]
        );
    }
}
