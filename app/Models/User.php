<?php

namespace App\Models;

use App\Enums\ProfileStatus;
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
        'language',
        'theme',
        'profile_completed',
        'profile_status',
        'profile_rejection_reason',
        'profile_reviewed_by',
        'profile_reviewed_at',
        'profile_submitted_at',
        'is_active',
        'phone_verified_at',
        'email_verified_at',
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
            'profile_status' => ProfileStatus::class,
            'profile_reviewed_at' => 'datetime',
            'profile_submitted_at' => 'datetime',
            'is_active' => 'boolean',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
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

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin') || $this->hasRole('admin');
    }

    public function isEmployee(): bool
    {
        return $this->user_type === UserType::Employee;
    }

    public function isCitizen(): bool
    {
        return $this->hasRole('citizen');
    }

    public function isDashboardUser(): bool
    {
        return in_array($this->user_type, [UserType::Admin, UserType::Employee], true);
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }

        $this->loadMissing('role.permissions');

        return $this->role?->permissions->pluck('name')->values()->all() ?? [];
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $this->loadMissing('role.permissions');

        return $this->role?->permissions->contains('name', $permission) ?? false;
    }

    public function hasCompletedProfile(): bool
    {
        return (bool) $this->profile_completed;
    }

    public function isProfilePendingReview(): bool
    {
        return $this->profileStatus() === ProfileStatus::PendingReview;
    }

    public function isProfileApproved(): bool
    {
        return $this->profileStatus() === ProfileStatus::Approved;
    }

    public function isProfileRejected(): bool
    {
        return $this->profileStatus() === ProfileStatus::Rejected;
    }

    public function canUseCitizenServices(): bool
    {
        return $this->isCitizen()
            && $this->hasCompletedProfile()
            && $this->isProfileApproved()
            && $this->is_active;
    }

    public function profileStatus(): ProfileStatus
    {
        if ($this->profile_status instanceof ProfileStatus) {
            return $this->profile_status;
        }

        return ProfileStatus::tryFrom((string) $this->profile_status) ?? ProfileStatus::Incomplete;
    }
}
