<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class License extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'license_number',
        'citizen_id',
        'license_type_id',
        'application_id',
        'status',
        'issue_date',
        'expiry_date',
    ];

    protected function casts(): array
    {
        return [
            'status' => LicenseStatus::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
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

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }
}
