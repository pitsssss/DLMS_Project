<?php

namespace Database\Seeders;

use App\Enums\ProfileStatus;
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
            ['phone' => '0988888888'],
            [
                'name' => 'موظف تجريبي',
                'email' => 'employee@example.com',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'user_type' => UserType::Employee,
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );
    }
}
