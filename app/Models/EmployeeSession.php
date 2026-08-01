<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class EmployeeSession extends Model
{
    protected $fillable = [
        'session_uuid',
        'user_id',
        'auth_driver',
        'personal_access_token_id',
        'hashed_session_identifier',
        'logged_in_at',
        'last_seen_at',
        'logged_out_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
        'ended_reason',
        'initial_ip_address',
        'last_ip_address',
        'user_agent',
        'device_type',
        'operating_system',
        'browser',
        'browser_version',
    ];

    protected $hidden = [
        'personal_access_token_id',
        'hashed_session_identifier',
    ];

    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'logged_out_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            if (empty($session->session_uuid)) {
                $session->session_uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'session_uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function isCredentialPresent(): bool
    {
        return $this->personal_access_token_id !== null
            && $this->relationLoaded('personalAccessToken')
                ? $this->personalAccessToken !== null
                : $this->personalAccessToken()->exists();
    }
}
