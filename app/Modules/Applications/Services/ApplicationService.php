<?php

namespace App\Modules\Applications\Services;

use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApplicationService
{
    public function __construct(
        private readonly ApplicationRepository $applications
    ) {}

    public function createDraft(User $citizen, int $licenseTypeId, int $serviceTypeId): LicenseApplication
    {
        $this->assertCitizenCanApply($citizen);

        return $this->applications->createDraftForCitizen($citizen, $licenseTypeId, $serviceTypeId);
    }

    /**
     * @return LengthAwarePaginator<int, LicenseApplication>
     */
    public function paginateForCitizen(User $citizen, int $perPage): LengthAwarePaginator
    {
        $this->assertCitizen($citizen);

        return LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->with(['licenseType', 'serviceType', 'currentTestType'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getForCitizen(User $citizen, int $applicationId): LicenseApplication
    {
        $this->assertCitizen($citizen);

        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('messages.applications.not_found', 404);
        }

        return $application;
    }

    private function assertCitizen(User $citizen): void
    {
        if (! $citizen->isCitizen()) {
            throw new ApiException('messages.applications.citizen_only', 403);
        }
    }

    private function assertCitizenCanApply(User $citizen): void
    {
        $this->assertCitizen($citizen);

        if ($citizen->email_verified_at === null) {
            throw new ApiException('messages.applications.verify_email_first', 403);
        }

        if (! $citizen->profile_completed) {
            throw new ApiException('messages.applications.complete_profile_first', 403);
        }
    }
}
