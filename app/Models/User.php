<?php

namespace App\Models;

use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'national_id',
        'email',
        'password',
        'role_id',
        'user_type',
        'birth_date',
        'governorate',
        'address',
        'profile_completed',
        'is_active',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'birth_date' => 'date',
            'profile_completed' => 'boolean',
            'is_active' => 'boolean',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function licenseApplications(): HasMany
    {
        return $this->hasMany(LicenseApplication::class, 'citizen_id');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class, 'citizen_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class, 'citizen_id');
    }

    public function hasRole(string $role): bool
    {
        return $this->role?->name === $role;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isEmployee(): bool
    {
        return $this->hasRole('employee');
    }

    public function isCitizen(): bool
    {
        return $this->hasRole('citizen');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $this->loadMissing('role.permissions');

        return $this->role?->permissions->contains('name', $permission) ?? false;
    }
}
