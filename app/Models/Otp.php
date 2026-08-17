<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $fillable = [
        'email',
        'phone',
        'code',
        'purpose',
        'expires_at',
        'verified_at',
        'failed_attempts',
        'invalidated_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_attempts' => 'integer',
            'invalidated_at' => 'datetime',
        ];
    }
}
