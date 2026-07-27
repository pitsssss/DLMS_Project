<?php

namespace App\Models;

use App\Enums\PaymentGatewayEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGatewayEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payment_id',
        'processing_status',
        'payload_hash',
        'safe_error_code',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processing_status' => PaymentGatewayEventStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
