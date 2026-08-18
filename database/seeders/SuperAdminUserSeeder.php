<?php

namespace Database\Seeders;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();

        User::updateOrCreate(
            ['email' => 'superadmin@syrtak.gov.sy'],
            [
                'name' => 'بيتر طوس - مدير النظام العام',
                'phone' => '0999999998',
                'password' => Hash::make('password123'),
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
