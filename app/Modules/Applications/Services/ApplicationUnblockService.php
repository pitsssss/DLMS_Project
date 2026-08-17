<?php

namespace App\Modules\Applications\Services;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\ServiceCode;
use App\Exceptions\ApiException;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Modules\Licenses\Services\LicenseIssuanceEligibilityService;
use App\Modules\Licenses\Services\LicenseService;
use App\Services\AuditLogService;
use App\Support\Msg;
use Illuminate\Support\Facades\DB;

class ApplicationUnblockService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly LicenseService $licenses,
        private readonly LicenseIssuanceEligibilityService $issuanceEligibility,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @return array{application: LicenseApplication, license: License}
     */
    public function unblockFromApplication(User $employee, int $applicationId): array
    {
        return DB::transaction(function () use ($employee, $applicationId) {
            $application = LicenseApplication::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->with(['serviceType', 'relatedLicense'])
                ->first();

            if ($application === null) {
                throw new ApiException('messages.applications.not_found', 404);
            }

            $this->assertReadyForUnblockAction($application);

            $license = $this->licenses->unblock($employee, (int) $application->related_license_id);

            $application = $this->applications->transitionStatus(
                $application,
                ApplicationStatus::Completed,
                $employee,
                Msg::get('applications.note_unblock_completed')
            );

            $this->auditLogs->log(
                $employee,
                'application.unblock_completed',
                'license_application',
                $application->id,
                ['status' => ApplicationStatus::Approved->value],
                [
                    'status' => ApplicationStatus::Completed->value,
                    'related_license_id' => $application->related_license_id,
                    'license_status' => $license->status->value,
                ]
            );

            return [
                'application' => $application->fresh(['licenseType', 'serviceType', 'relatedLicense']),
                'license' => $license,
            ];
        });
    }

    public function rejectApprovedUnblockApplication(User $employee, int $applicationId, string $reason): LicenseApplication
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new ApiException('messages.applications.rejection_reason_required', 422);
        }

        if (mb_strlen($reason) > 1000) {
            throw new ApiException('messages.applications.rejection_reason_too_long', 422);
        }

        return DB::transaction(function () use ($employee, $applicationId, $reason) {
            $application = LicenseApplication::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->with(['serviceType', 'relatedLicense'])
                ->first();

            if ($application === null) {
                throw new ApiException('messages.applications.not_found', 404);
            }

            $this->assertUnblockServiceApplication($application);

            if ($application->status !== ApplicationStatus::Approved) {
                throw new ApiException('messages.applications.cannot_reject_status', 422);
            }

            return $this->applications->transitionStatus(
                $application,
                ApplicationStatus::Rejected,
                $employee,
                Msg::get('applications.note_unblock_rejected'),
                $reason
            );
        });
    }

    private function assertReadyForUnblockAction(LicenseApplication $application): void
    {
        $this->assertUnblockServiceApplication($application);

        if ($application->status === ApplicationStatus::Completed) {
            throw new ApiException('messages.applications.already_completed', 422);
        }

        if ($application->status !== ApplicationStatus::Approved) {
            throw new ApiException('messages.applications.must_be_approved_unblock', 422);
        }

        if ($application->related_license_id === null) {
            throw new ApiException('messages.applications.related_license_required', 422);
        }

        $license = License::query()
            ->whereKey($application->related_license_id)
            ->lockForUpdate()
            ->first();

        if ($license === null) {
            throw new ApiException('messages.licenses.not_found', 404);
        }

        if ((int) $license->citizen_id !== (int) $application->citizen_id) {
            throw new ApiException('messages.applications.license_not_owned', 403);
        }

        if ($license->status !== LicenseStatus::Blocked) {
            throw new ApiException('messages.applications.license_not_blocked', 422);
        }

        if ($this->citizenHasUnpaidFines((int) $application->citizen_id)) {
            throw new ApiException('messages.licenses.unpaid_fines_unblock', 422);
        }

        if (! $this->issuanceEligibility->applicationFeePaid($application)) {
            throw new ApiException('messages.licenses.payment_required', 422);
        }

        if (! $this->issuanceEligibility->allRequiredDocumentsApproved($application)) {
            throw new ApiException('messages.licenses.documents_required', 422);
        }
    }

    private function assertUnblockServiceApplication(LicenseApplication $application): void
    {
        $application->loadMissing('serviceType');

        if (! ServiceWorkflow::usesUnblockWorkflow($application->serviceType?->code)) {
            throw new ApiException('messages.applications.not_unblock_service', 422);
        }
    }

    private function citizenHasUnpaidFines(int $citizenId): bool
    {
        return Fine::query()
            ->where('citizen_id', $citizenId)
            ->where('status', FineStatus::Unpaid)
            ->exists();
    }
}
