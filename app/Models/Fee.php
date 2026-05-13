<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fee extends Model
{
    protected $fillable = [
        'license_type_id',
        'service_type_id',
        'test_type_id',
        'name',
        'code',
        'amount',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function testType(): BelongsTo
    {
        return $this->belongsTo(TestType::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
