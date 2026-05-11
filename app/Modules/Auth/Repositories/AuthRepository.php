<?php

namespace App\Modules\Auth\Repositories;

use App\Models\Role;
use App\Models\User;

class AuthRepository
{
    public function findByPhone(string $phone): ?User
    {
        return User::query()->where('phone', $phone)->first();
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function findByLoginIdentifier(?string $email, ?string $identifier): ?User
    {
        if ($email) {
            return $this->findByEmail($email);
        }

        if ($identifier === null || $identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return $this->findByEmail($identifier);
        }

        return $this->findByPhone($identifier);
    }

    public function findCitizenRole(): Role
    {
        return Role::query()->where('name', 'citizen')->firstOrFail();
    }

    public function createCitizen(array $attributes): User
    {
        $role = $this->findCitizenRole();

        return User::query()->create([
            ...$attributes,
            'role_id' => $role->id,
        ]);
    }

    public function updateUser(User $user, array $attributes): User
    {
        $user->fill($attributes);
        $user->save();

        return $user->fresh();
    }
}
