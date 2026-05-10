<?php

namespace App\Models;

use App\Enums\TestResultStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestResult extends Model
{
    protected $fillable = [
        'application_id',
        'test_appointment_id',
        'test_type_id',
        'result',
        'attempt_number',
        'notes',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => TestResultStatus::class,
            'recorded_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LicenseApplication::class, 'application_id');
    }

    public function testAppointment(): BelongsTo
    {
        return $this->belongsTo(TestAppointment::class, 'test_appointment_id');
    }

    public function testType(): BelongsTo
    {
        return $this->belongsTo(TestType::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
