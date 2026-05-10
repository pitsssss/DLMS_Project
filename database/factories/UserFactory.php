<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('09########'),
            'national_id' => null,
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => Role::query()->where('name', 'citizen')->value('id')
                ?? Role::create(['name' => 'citizen'])->id,
            'user_type' => UserType::Citizen,
            'birth_date' => null,
            'governorate' => null,
            'address' => null,
            'profile_completed' => false,
            'is_active' => true,
            'phone_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }
}
