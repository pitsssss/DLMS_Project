<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'payment_number',
        'user_id',
        'application_id',
        'fine_id',
        'fee_id',
        'payable_type',
        'payable_id',
        'amount',
        'currency',
        'status',
        'provider',
        'provider_reference',
        'paid_at',
        'metadata',
        'failure_code',
        'failure_message',
        'failed_at',
        'last_verified_at',
        'settled_obligation_key',
        'active_obligation_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LicenseApplication::class, 'application_id');
    }

    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function obligationKey(int $applicationId, int $feeId): string
    {
        return "application:{$applicationId}:fee:{$feeId}";
    }

    public static function fineObligationKey(int $fineId): string
    {
        return "fine:{$fineId}";
    }

    public function isApplicationPayment(): bool
    {
        return $this->fine_id === null && $this->application_id !== null;
    }

    public function isFinePayment(): bool
    {
        return $this->fine_id !== null && $this->application_id === null;
    }

    public function isSupportedPayable(): bool
    {
        return $this->isApplicationPayment() || $this->isFinePayment();
    }

    public function obligationKeyValue(): ?string
    {
        if ($this->isApplicationPayment() && $this->fee_id !== null) {
            return self::obligationKey((int) $this->application_id, (int) $this->fee_id);
        }

        if ($this->isFinePayment()) {
            return self::fineObligationKey((int) $this->fine_id);
        }

        return null;
    }

    public function isTerminalCompleted(): bool
    {
        return $this->status === PaymentStatus::Completed;
    }

    public function isActiveAttempt(): bool
    {
        return in_array($this->status, [PaymentStatus::Pending, PaymentStatus::UnderVerification], true);
    }
}
