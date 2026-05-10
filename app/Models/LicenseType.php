<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicenseType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'minimum_age',
        'validity_years',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function licenseApplications(): HasMany
    {
        return $this->hasMany(LicenseApplication::class);
    }

    public function requiredDocuments(): HasMany
    {
        return $this->hasMany(RequiredDocument::class);
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }
}
