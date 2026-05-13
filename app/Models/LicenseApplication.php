<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class LicenseApplication extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'application_number',
        'citizen_id',
        'license_type_id',
        'service_type_id',
        'status',
        'current_test_type_id',
        'rejection_reason',
        'submitted_at',
        'approved_at',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function currentTestType(): BelongsTo
    {
        return $this->belongsTo(TestType::class, 'current_test_type_id');
    }

    public function applicationDocuments(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class, 'application_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'application_id');
    }

    public function testAppointments(): HasMany
    {
        return $this->hasMany(TestAppointment::class, 'application_id');
    }

    public function testResults(): HasMany
    {
        return $this->hasMany(TestResult::class, 'application_id');
    }

    public function license(): HasOne
    {
        return $this->hasOne(License::class, 'application_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class, 'application_id');
    }
}
