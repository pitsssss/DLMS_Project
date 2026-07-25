<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApplicationDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'application_id',
        'required_document_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'status',
        'rejection_reason',
        'rejection_reason_code',
        'rejection_details',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LicenseApplication::class, 'application_id');
    }

    public function requiredDocument(): BelongsTo
    {
        return $this->belongsTo(RequiredDocument::class, 'required_document_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
