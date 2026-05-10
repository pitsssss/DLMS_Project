<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentSlot extends Model
{
    protected $fillable = [
        'test_type_id',
        'date',
        'start_time',
        'end_time',
        'capacity',
        'booked_count',
        'location',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function testType(): BelongsTo
    {
        return $this->belongsTo(TestType::class);
    }

    public function testAppointments(): HasMany
    {
        return $this->hasMany(TestAppointment::class, 'appointment_slot_id');
    }
}
