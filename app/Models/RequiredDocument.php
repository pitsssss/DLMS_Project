<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequiredDocument extends Model
{
    protected $fillable = [
        'license_type_id',
        'service_type_id',
        'name',
        'code',
        'is_required',
        'allowed_extensions',
        'max_size_kb',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_extensions' => 'array',
            'is_required' => 'boolean',
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

    public function applicationDocuments(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class, 'required_document_id');
    }
}
