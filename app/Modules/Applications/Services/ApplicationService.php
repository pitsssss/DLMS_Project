<?php

namespace App\Modules\Applications\Services;

use App\Enums\ServiceCode;
use App\Exceptions\ApiException;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Modules\Auth\Services\ProfileService;
use App\Modules\Notifications\Services\NotificationService;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApplicationService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ProfileService $profiles,
        private readonly LicenseServiceEligibilityService $eligibility,
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromPayload(User $citizen, array $data): LicenseApplication
    {
        $serviceType = $this->resolveServiceType($data);
        $serviceCode = ServiceWorkflow::fromServiceType($serviceType);

        if ($serviceCode !== null && ServiceWorkflow::requiresRelatedLicense($serviceCode)) {
            $relatedLicenseId = isset($data['related_license_id']) ? (int) $data['related_license_id'] : 0;

            if ($relatedLicenseId <= 0) {
                throw new ApiException('messages.applications.related_license_required', 422);
            }

            $license = License::query()
                ->whereKey($relatedLicenseId)
                ->with('licenseType')
                ->first();

            if ($license === null) {
                throw new ApiException('messages.licenses.not_found', 404);
            }

            if ((int) $license->citizen_id !== (int) $citizen->id) {
                throw new ApiException('messages.applications.license_not_owned', 403);
            }

            $eligibility = $this->eligibility->check($citizen, $license, $serviceCode);

            if (! $eligibility['allowed']) {
                throw new ApiException($eligibility['message'] ?? 'messages.applications.license_not_eligible', 422);
            }

            $this->assertCitizenCanApply($citizen);
            $this->assertNoDuplicateActiveApplicationByRelatedLicense($citizen, $serviceType->id, $license->id);

            $application = $this->applications->createDraftForCitizen(
                $citizen,
                $license->license_type_id,
                $serviceType->id,
                $license->id
            );

            $this->logApplicationCreated($citizen, $application, $serviceCode);

            return $application;
        }

        $licenseType = $this->resolveLicenseType($data);
        $this->assertCitizenCanApply($citizen);
        $this->assertNoDuplicateActiveApplication($citizen, $licenseType->id, $serviceType->id);

        if (! empty($data['related_license_id'])) {
            throw new ApiException('messages.applications.related_license_not_allowed', 422);
        }

        $application = $this->applications->createDraftForCitizen(
            $citizen,
            $licenseType->id,
            $serviceType->id
        );

        $this->logApplicationCreated($citizen, $application, $serviceCode ?? ServiceCode::NewLicense);

        return $application;
    }

    public function createDraft(
        User $citizen,
        int $licenseTypeId,
        int $serviceTypeId,
        ?int $relatedLicenseId = null,
    ): LicenseApplication {
        return $this->createFromPayload($citizen, [
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'related_license_id' => $relatedLicenseId,
        ]);
    }

    public function findActiveApplication(
        User $citizen,
        int $licenseTypeId,
        int $serviceTypeId,
    ): ?LicenseApplication {
        $this->assertCitizen($citizen);

        return $this->applications->findActiveForCitizen($citizen, $licenseTypeId, $serviceTypeId);
    }

    public function findActiveApplicationByRelatedLicense(
        User $citizen,
        string $serviceTypeCode,
        int $relatedLicenseId,
    ): ?LicenseApplication {
        $this->assertCitizen($citizen);

        $serviceType = ServiceType::query()
            ->where('code', $serviceTypeCode)
            ->where('is_active', true)
            ->first();

        if ($serviceType === null) {
            return null;
        }

        return $this->applications->findActiveForCitizenByRelatedLicense(
            $citizen,
            $serviceType->id,
            $relatedLicenseId
        );
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
            ->with(['licenseType', 'serviceType', 'relatedLicense', 'currentTestType'])
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveServiceType(array $data): ServiceType
    {
        if (! empty($data['service_type_code'])) {
            $serviceType = ServiceType::query()
                ->where('code', (string) $data['service_type_code'])
                ->where('is_active', true)
                ->first();

            if ($serviceType === null) {
                throw new ApiException('messages.applications.invalid_service_type', 422);
            }

            return $serviceType;
        }

        if (! empty($data['service_type_id'])) {
            $serviceType = ServiceType::query()
                ->whereKey((int) $data['service_type_id'])
                ->where('is_active', true)
                ->first();

            if ($serviceType === null) {
                throw new ApiException('messages.applications.invalid_service_type', 422);
            }

            return $serviceType;
        }

        throw new ApiException('messages.applications.invalid_service_type', 422);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveLicenseType(array $data): LicenseType
    {
        if (! empty($data['license_type_code'])) {
            $licenseType = LicenseType::query()
                ->where('code', (string) $data['license_type_code'])
                ->where('is_active', true)
                ->first();

            if ($licenseType === null) {
                throw new ApiException('messages.applications.invalid_license_type', 422);
            }

            return $licenseType;
        }

        if (! empty($data['license_type_id'])) {
            $licenseType = LicenseType::query()
                ->whereKey((int) $data['license_type_id'])
                ->where('is_active', true)
                ->first();

            if ($licenseType === null) {
                throw new ApiException('messages.applications.invalid_license_type', 422);
            }

            return $licenseType;
        }

        throw new ApiException('messages.applications.license_type_required', 422);
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
            throw new ApiException('messages.profile.must_complete', 403);
        }

        $this->profiles->assertCanUseCitizenServices($citizen);
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

    private function assertNoDuplicateActiveApplicationByRelatedLicense(
        User $citizen,
        int $serviceTypeId,
        int $relatedLicenseId,
    ): void {
        if ($this->applications->findActiveForCitizenByRelatedLicense($citizen, $serviceTypeId, $relatedLicenseId) !== null) {
            throw new ApiException('messages.applications.duplicate_active_application_license', 422);
        }
    }

    private function logApplicationCreated(User $citizen, LicenseApplication $application, ServiceCode $serviceCode): void
    {
        $action = match ($serviceCode) {
            ServiceCode::RenewLicense => 'application.renewal_created',
            ServiceCode::LostReplacement => 'application.lost_replacement_created',
            ServiceCode::DamagedReplacement => 'application.damaged_replacement_created',
            default => 'application.created',
        };

        $this->auditLogs->log(
            $citizen,
            $action,
            'license_application',
            $application->id,
            null,
            [
                'service_type' => $serviceCode->value,
                'related_license_id' => $application->related_license_id,
            ]
        );

        $this->notifications->sendToUser(
            $citizen->id,
            __('messages.notifications.application_created_title'),
            __('messages.notifications.application_created_body'),
            'application.created',
            ['application_id' => $application->id]
        );
    }
}
