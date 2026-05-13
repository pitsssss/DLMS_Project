<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class TestAppointment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'application_id',
        'citizen_id',
        'appointment_slot_id',
        'test_type_id',
        'status',
        'scheduled_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LicenseApplication::class, 'application_id');
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function appointmentSlot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'appointment_slot_id');
    }

    public function testType(): BelongsTo
    {
        return $this->belongsTo(TestType::class);
    }

    public function testResult(): HasOne
    {
        return $this->hasOne(TestResult::class, 'test_appointment_id');
    }
}
