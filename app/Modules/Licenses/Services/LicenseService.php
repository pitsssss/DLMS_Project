<?php

namespace App\Modules\Licenses\Services;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\ServiceCode;
use App\Exceptions\ApiException;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Modules\Licenses\Repositories\LicenseRepository;
use App\Modules\Notifications\Services\NotificationService;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LicenseService
{
    public function __construct(
        private readonly LicenseRepository $licenses,
        private readonly ApplicationRepository $applications,
        private readonly LicenseIssuanceEligibilityService $issuanceEligibility,
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications
    ) {}

    /**
     * @return Collection<int, License>
     */
    public function listForCitizen(User $citizen): Collection
    {
        return $this->licenses->listForCitizen($citizen);
    }

    public function showForCitizen(User $citizen, int $licenseId): License
    {
        $license = $this->licenses->findOwnedByCitizen($citizen, $licenseId);

        if ($license === null) {
            throw new ApiException('messages.licenses.not_found', 404);
        }

        return $license;
    }

    public function issueForApplication(User $employee, int $applicationId): License
    {
        return DB::transaction(function () use ($employee, $applicationId) {
            $application = LicenseApplication::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->with(['licenseType', 'serviceType', 'relatedLicense'])
                ->first();

            if ($application === null) {
                throw new ApiException('messages.applications.not_found', 404);
            }

            $this->issuanceEligibility->assertReady($application);

            if ($this->licenses->existsForApplication($application->id)) {
                throw new ApiException('messages.licenses.already_issued', 422);
            }

            $serviceCode = ServiceWorkflow::fromServiceType($application->serviceType);
            $license = match ($serviceCode) {
                ServiceCode::RenewLicense => $this->issueRenewalLicense($application),
                ServiceCode::LostReplacement => $this->issueReplacementLicense($application, ServiceCode::LostReplacement),
                ServiceCode::DamagedReplacement => $this->issueReplacementLicense($application, ServiceCode::DamagedReplacement),
                default => $this->issueNewLicense($application),
            };

            $application->approved_at ??= now();
            $application->issued_at = now();
            $application->save();

            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::LicenseIssued,
                $employee,
                __('messages.licenses.note_issued')
            );

            $auditAction = match ($serviceCode) {
                ServiceCode::RenewLicense => 'license.renewed',
                ServiceCode::LostReplacement => 'license.lost_replacement_issued',
                ServiceCode::DamagedReplacement => 'license.damaged_replacement_issued',
                default => 'license.issued',
            };

            $this->auditLogs->log(
                $employee,
                $auditAction,
                'license',
                $license->id,
                null,
                ['license_number' => $license->license_number, 'application_id' => $application->id]
            );

            $this->notifications->sendToUser(
                $application->citizen_id,
                __('messages.notifications.license_issued_title'),
                __('messages.notifications.license_issued_body'),
                'license.issued',
                ['license_id' => $license->id, 'application_id' => $application->id]
            );

            return $license->fresh(['licenseType', 'application']);
        });
    }

    public function renew(User $citizen, int $licenseId): License
    {
        return DB::transaction(function () use ($citizen, $licenseId) {
            $old = $this->requireOwnedRenewableLicense($citizen, $licenseId);

            $issueDate = now()->toDateString();
            $expiryDate = now()->addYears((int) config('license.validity_years', 10))->toDateString();

            $newLicense = $this->licenses->create([
                'license_number' => $this->licenses->generateUniqueLicenseNumber(),
                'citizen_id' => $old->citizen_id,
                'license_type_id' => $old->license_type_id,
                'application_id' => $old->application_id,
                'status' => LicenseStatus::Active,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
            ]);

            $old->status = LicenseStatus::Renewed;
            $old->save();

            return $newLicense->fresh(['licenseType', 'application']);
        });
    }

    public function replace(User $citizen, int $licenseId, string $replacementType): License
    {
        if (! in_array($replacementType, ['lost', 'damaged'], true)) {
            throw new ApiException('messages.licenses.replacement_type_invalid', 422);
        }

        return DB::transaction(function () use ($citizen, $licenseId) {
            $old = $this->licenses->findOwnedByCitizen($citizen, $licenseId);

            if ($old === null) {
                throw new ApiException('messages.licenses.not_found', 404);
            }

            if ($old->status === LicenseStatus::Blocked) {
                throw new ApiException('messages.licenses.blocked_cannot_replace', 422);
            }

            if (! in_array($old->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
                throw new ApiException('messages.licenses.cannot_replace_status', 422);
            }

            $this->assertCitizenHasNoUnpaidFines($citizen->id);

            $newLicense = $this->licenses->create([
                'license_number' => $this->licenses->generateUniqueLicenseNumber(),
                'citizen_id' => $old->citizen_id,
                'license_type_id' => $old->license_type_id,
                'application_id' => $old->application_id,
                'status' => LicenseStatus::Active,
                'issue_date' => now()->toDateString(),
                'expiry_date' => $old->expiry_date,
            ]);

            $old->status = LicenseStatus::Inactive;
            $old->save();

            return $newLicense->fresh(['licenseType', 'application']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function requestUnblock(User $citizen, int $licenseId): array
    {
        $license = $this->licenses->findOwnedByCitizen($citizen, $licenseId);

        if ($license === null) {
            throw new ApiException('messages.licenses.not_found', 404);
        }

        if ($license->status !== LicenseStatus::Blocked) {
            throw new ApiException('messages.licenses.only_blocked_unblock', 422);
        }

        if ($this->citizenHasUnpaidFines($citizen->id)) {
            throw new ApiException('messages.licenses.fines_before_unblock', 422);
        }

        return [
            'license_id' => $license->id,
            'license_number' => $license->license_number,
            'status' => $license->status->value,
            'message' => __('messages.licenses.unblock_registered'),
        ];
    }

    public function block(User $actor, int $licenseId, ?string $reason = null): License
    {
        return DB::transaction(function () use ($actor, $licenseId, $reason) {
            $license = License::query()->whereKey($licenseId)->lockForUpdate()->first();

            if ($license === null) {
                throw new ApiException('messages.licenses.not_found', 404);
            }

            if ($license->status === LicenseStatus::Blocked) {
                return $license->fresh(['licenseType', 'application', 'citizen']);
            }

            if (! in_array($license->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
                throw new ApiException('messages.licenses.cannot_block_status', 422);
            }

            $previousStatus = $license->status;
            $license->status = LicenseStatus::Blocked;
            $license->save();

            $this->auditLogs->log(
                $actor,
                'license.blocked',
                'license',
                $license->id,
                ['status' => $previousStatus->value],
                ['status' => LicenseStatus::Blocked->value]
            );

            $this->notifications->sendToUser(
                $license->citizen_id,
                __('messages.notifications.license_blocked_title'),
                __('messages.notifications.license_blocked_body'),
                'license.blocked',
                ['license_id' => $license->id, 'license_number' => $license->license_number]
            );

            return $license->fresh(['licenseType', 'application', 'citizen']);
        });
    }

    public function unblock(User $actor, int $licenseId): License
    {
        return DB::transaction(function () use ($actor, $licenseId) {
            $license = License::query()->whereKey($licenseId)->lockForUpdate()->first();

            if ($license === null) {
                throw new ApiException('messages.licenses.not_found', 404);
            }

            if ($license->status !== LicenseStatus::Blocked) {
                throw new ApiException('messages.licenses.only_blocked_can_unblock', 422);
            }

            if ($this->citizenHasUnpaidFines($license->citizen_id)) {
                throw new ApiException('messages.licenses.unpaid_fines_unblock', 422);
            }

            $license->status = $license->expiry_date->isPast()
                ? LicenseStatus::Expired
                : LicenseStatus::Active;
            $license->save();

            $this->auditLogs->log(
                $actor,
                'license.unblocked',
                'license',
                $license->id,
                ['status' => LicenseStatus::Blocked->value],
                ['status' => $license->status->value]
            );

            $this->notifications->sendToUser(
                $license->citizen_id,
                __('messages.notifications.license_unblocked_title'),
                __('messages.notifications.license_unblocked_body'),
                'license.unblocked',
                ['license_id' => $license->id]
            );

            return $license->fresh(['licenseType', 'application', 'citizen']);
        });
    }

    private function issueNewLicense(LicenseApplication $application): License
    {
        $issueDate = now()->toDateString();
        $expiryDate = now()->addYears((int) config('license.validity_years', 10))->toDateString();

        return $this->licenses->create([
            'license_number' => $this->licenses->generateUniqueLicenseNumber(),
            'citizen_id' => $application->citizen_id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => $issueDate,
            'expiry_date' => $expiryDate,
        ]);
    }

    private function issueRenewalLicense(LicenseApplication $application): License
    {
        $old = $this->requireRelatedLicense($application);

        $issueDate = now()->toDateString();
        $expiryDate = now()->addYears((int) config('license.validity_years', 10))->toDateString();

        $license = $this->licenses->create([
            'license_number' => $this->licenses->generateUniqueLicenseNumber(),
            'citizen_id' => $application->citizen_id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => $issueDate,
            'expiry_date' => $expiryDate,
        ]);

        $old->status = LicenseStatus::Renewed;
        $old->save();

        return $license;
    }

    private function issueReplacementLicense(LicenseApplication $application, ServiceCode $serviceCode): License
    {
        $old = $this->requireRelatedLicense($application);

        $license = $this->licenses->create([
            'license_number' => $this->licenses->generateUniqueLicenseNumber(),
            'citizen_id' => $application->citizen_id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => $old->expiry_date,
        ]);

        $old->status = LicenseStatus::Inactive;
        $old->save();

        return $license;
    }

    private function requireRelatedLicense(LicenseApplication $application): License
    {
        $application->loadMissing('relatedLicense');

        if ($application->relatedLicense === null) {
            throw new ApiException('messages.applications.related_license_required', 422);
        }

        return $application->relatedLicense;
    }

    private function requireOwnedRenewableLicense(User $citizen, int $licenseId): License
    {
        $license = $this->licenses->findOwnedByCitizen($citizen, $licenseId);

        if ($license === null) {
            throw new ApiException('messages.licenses.not_found', 404);
        }

        if (! in_array($license->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
            throw new ApiException('messages.licenses.cannot_renew_status', 422);
        }

        $graceDays = (int) config('license.renewal_grace_days', 90);
        $renewableFrom = $license->expiry_date->copy()->subDays($graceDays);

        if (now()->toDateString() < $renewableFrom->toDateString() && $license->status === LicenseStatus::Active) {
            throw new ApiException('messages.licenses.not_eligible_renewal', 422);
        }

        $this->assertCitizenHasNoUnpaidFines($citizen->id);

        return $license;
    }

    private function assertCitizenHasNoUnpaidFines(int $citizenId): void
    {
        if ($this->citizenHasUnpaidFines($citizenId)) {
            throw new ApiException('messages.licenses.unpaid_fines_continue', 422);
        }
    }

    private function citizenHasUnpaidFines(int $citizenId): bool
    {
        return \App\Models\Fine::query()
            ->where('citizen_id', $citizenId)
            ->where('status', FineStatus::Unpaid)
            ->exists();
    }
}
