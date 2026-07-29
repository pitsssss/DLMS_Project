<?php

namespace App\Models;

use App\Modules\Payments\Support\FeeIdentity;
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
        'identity_key',
        'amount',
        'currency',
        'is_active',
        'created_by',
        'updated_by',
        'deactivated_at',
        'deactivated_by',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Fee $fee): void {
            $fee->identity_key = FeeIdentity::keyForFee($fee);
        });
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function hasPaymentUsage(): bool
    {
        return $this->payments()->exists();
    }
}
