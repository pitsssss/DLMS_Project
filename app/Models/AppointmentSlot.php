<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Modules\Appointments\Support\SlotIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentSlot extends Model
{
    protected $fillable = [
        'test_type_id',
        'appointment_center_id',
        'identity_key',
        'date',
        'start_time',
        'end_time',
        'capacity',
        'booked_count',
        'location',
        'is_active',
        'created_by',
        'updated_by',
        'deactivated_at',
        'deactivated_by',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AppointmentSlot $slot): void {
            if ($slot->test_type_id && $slot->date && $slot->start_time && $slot->end_time) {
                $slot->identity_key = SlotIdentity::keyForSlot($slot);
            }
        });
    }

    public function testType(): BelongsTo
    {
        return $this->belongsTo(TestType::class);
    }

    public function appointmentCenter(): BelongsTo
    {
        return $this->belongsTo(AppointmentCenter::class);
    }

    public function testAppointments(): HasMany
    {
        return $this->hasMany(TestAppointment::class, 'appointment_slot_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function hasActiveBookings(): bool
    {
        return $this->testAppointments()
            ->where('status', AppointmentStatus::Booked)
            ->exists();
    }

    public function activeBookedCount(): int
    {
        return (int) $this->testAppointments()
            ->where('status', AppointmentStatus::Booked)
            ->count();
    }
}
