<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use App\Modules\Licenses\Support\LicenseEffectiveStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class License extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'license_number',
        'citizen_id',
        'license_type_id',
        'application_id',
        'issued_by',
        'previous_license_id',
        'status',
        'issue_date',
        'expiry_date',
        'blocked_at',
        'blocked_by',
        'block_reason',
        'verification_token',
        'printed_at',
        'printed_by',
        'print_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => LicenseStatus::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'blocked_at' => 'datetime',
            'printed_at' => 'datetime',
            'print_count' => 'integer',
        ];
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LicenseApplication::class, 'application_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    public function previousLicense(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_license_id');
    }

    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'previous_license_id');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(LicenseStatusHistory::class);
    }

    public function effectiveStatus(): LicenseStatus
    {
        return LicenseEffectiveStatus::resolve($this);
    }

    public function effectiveStatusValue(): string
    {
        return LicenseEffectiveStatus::value($this);
    }
}
