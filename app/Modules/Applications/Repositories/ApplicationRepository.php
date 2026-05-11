<?php

namespace App\Modules\Applications\Repositories;

use App\Enums\ApplicationStatus;
use App\Models\ApplicationStatusHistory;
use App\Models\LicenseApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplicationRepository
{
    public function generateUniqueApplicationNumber(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $number = 'APP-'.now()->format('Y').'-'.strtoupper(Str::random(10));
            if (! LicenseApplication::query()->where('application_number', $number)->exists()) {
                return $number;
            }
        }

        return 'APP-'.now()->format('Y').'-'.strtoupper(Str::uuid()->toString());
    }

    public function createDraftForCitizen(User $citizen, int $licenseTypeId, int $serviceTypeId): LicenseApplication
    {
        return DB::transaction(function () use ($citizen, $licenseTypeId, $serviceTypeId) {
            $application = LicenseApplication::query()->create([
                'application_number' => $this->generateUniqueApplicationNumber(),
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseTypeId,
                'service_type_id' => $serviceTypeId,
                'status' => ApplicationStatus::Draft,
                'current_test_type_id' => null,
                'rejection_reason' => null,
                'submitted_at' => null,
                'approved_at' => null,
                'issued_at' => null,
            ]);

            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'old_status' => null,
                'new_status' => ApplicationStatus::Draft,
                'changed_by' => $citizen->id,
                'reason' => null,
                'notes' => 'Application draft created.',
            ]);

            return $application->load(['licenseType', 'serviceType', 'currentTestType']);
        });
    }

    public function findOwnedByCitizen(User $citizen, int $applicationId): ?LicenseApplication
    {
        return LicenseApplication::query()
            ->whereKey($applicationId)
            ->where('citizen_id', $citizen->id)
            ->with(['licenseType', 'serviceType', 'currentTestType'])
            ->first();
    }
}
