<?php

namespace Database\Factories;

use App\Enums\ProfileStatus;
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
            'email_verified_at' => null,
            'phone_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withApprovedProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_completed' => true,
            'profile_status' => ProfileStatus::Approved,
            'profile_submitted_at' => now(),
            'profile_reviewed_at' => now(),
        ]);
    }

    public function withPendingProfileReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_completed' => true,
            'profile_status' => ProfileStatus::PendingReview,
            'profile_submitted_at' => now(),
            'profile_rejection_reason' => null,
            'profile_reviewed_by' => null,
            'profile_reviewed_at' => null,
        ]);
    }

    public function withRejectedProfile(?string $reason = 'بيانات غير مكتملة'): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_completed' => true,
            'profile_status' => ProfileStatus::Rejected,
            'profile_submitted_at' => now()->subDay(),
            'profile_rejection_reason' => $reason,
            'profile_reviewed_at' => now()->subHours(12),
        ]);
    }
}
