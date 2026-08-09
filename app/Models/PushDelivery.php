<?php

namespace App\Models;

use App\Enums\PushDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushDelivery extends Model
{
    protected $fillable = [
        'notification_id',
        'push_device_id',
        'delivery_key',
        'status',
        'attempts',
        'provider_message_id',
        'last_error_category',
        'last_http_status',
        'last_attempt_at',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PushDeliveryStatus::class,
            'attempts' => 'integer',
            'last_http_status' => 'integer',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function pushDevice(): BelongsTo
    {
        return $this->belongsTo(PushDevice::class);
    }

    public static function deliveryKey(int $notificationId, int $pushDeviceId): string
    {
        return "notification:{$notificationId}:device:{$pushDeviceId}";
    }
}
