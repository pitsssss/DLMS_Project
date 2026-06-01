<?php

namespace App\Modules\Applications\Services;

use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
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
        $this->assertNoDuplicateActiveApplication($citizen, $licenseTypeId, $serviceTypeId);

        return $this->applications->createDraftForCitizen($citizen, $licenseTypeId, $serviceTypeId);
    }

    public function findActiveApplication(
        User $citizen,
        int $licenseTypeId,
        int $serviceTypeId,
    ): ?LicenseApplication {
        $this->assertCitizen($citizen);

        return $this->applications->findActiveForCitizen($citizen, $licenseTypeId, $serviceTypeId);
    }

    public function findActiveApplicationByCodes(
        User $citizen,
        string $licenseTypeCode,
        string $serviceTypeCode,
    ): ?LicenseApplication {
        $this->assertCitizen($citizen);

        $licenseType = LicenseType::query()
            ->where('code', $licenseTypeCode)
            ->where('is_active', true)
            ->first();

        $serviceType = ServiceType::query()
            ->where('code', $serviceTypeCode)
            ->where('is_active', true)
            ->first();

        if ($licenseType === null || $serviceType === null) {
            return null;
        }

        return $this->findActiveApplication($citizen, $licenseType->id, $serviceType->id);
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

    private function assertNoDuplicateActiveApplication(
        User $citizen,
        int $licenseTypeId,
        int $serviceTypeId,
    ): void {
        if ($this->applications->findActiveForCitizen($citizen, $licenseTypeId, $serviceTypeId) !== null) {
            throw new ApiException('messages.applications.duplicate_active_application', 422);
        }
    }
}
