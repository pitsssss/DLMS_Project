<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'sequence_order',
        'max_attempts',
        'is_required',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function appointmentSlots(): HasMany
    {
        return $this->hasMany(AppointmentSlot::class);
    }

    public function testAppointments(): HasMany
    {
        return $this->hasMany(TestAppointment::class);
    }

    public function testResults(): HasMany
    {
        return $this->hasMany(TestResult::class);
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }
}
