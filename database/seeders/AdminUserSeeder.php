<?php

namespace Database\Seeders;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('name', 'admin')->firstOrFail();

        User::firstOrCreate(
            ['phone' => '0999999999'],
            [
                'name' => 'مدير النظام',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'user_type' => UserType::Admin,
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );
    }
}
