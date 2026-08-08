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
use App\Support\Msg;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LicenseService
{
    public function __construct(
        private readonly LicenseRepository $licenses,
        private readonly ApplicationRepository $applications,
        private readonly LicenseIssuanceEligibilityService $issuanceEligibility,
        private readonly LicenseLifecycleService $lifecycle,
        private readonly LicenseTransitionPolicy $transitions,
        private readonly NotificationService $notifications,
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

            $serviceCode = ServiceWorkflow::fromServiceType($application->serviceType);
            $license = match ($serviceCode) {
                ServiceCode::NewLicense => $this->issueNewLicense($application, $employee),
                ServiceCode::RenewLicense => $this->issueRenewalLicense($application, $employee),
                ServiceCode::LostReplacement => $this->issueReplacementLicense($application, ServiceCode::LostReplacement, $employee),
                ServiceCode::DamagedReplacement => $this->issueReplacementLicense($application, ServiceCode::DamagedReplacement, $employee),
                default => throw new ApiException('messages.licenses.service_not_issuable', 422),
            };

            $application->approved_at ??= now();
            $application->issued_at = now();
            $application->save();

            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::LicenseIssued,
                $employee,
                Msg::get('licenses.note_issued')
            );

            $auditAction = match ($serviceCode) {
                ServiceCode::RenewLicense => 'license.renewed',
                ServiceCode::LostReplacement => 'license.lost_replacement_issued',
                ServiceCode::DamagedReplacement => 'license.damaged_replacement_issued',
                ServiceCode::NewLicense => 'license.issued',
                default => 'license.issued',
            };

            $this->lifecycle->recordAudit(
                $employee,
                $auditAction,
                $license,
                null,
                [
                    'license_number' => $license->license_number,
                    'application_id' => $application->id,
                    'status' => $license->status->value,
                    'previous_license_id' => $license->previous_license_id,
                ]
            );

            $this->notifications->sendToUser(
                $application->citizen_id,
                Msg::get('notifications.license_issued_title'),
                Msg::get('notifications.license_issued_body'),
                'license.issued',
                ['license_id' => $license->id, 'application_id' => $application->id]
            );

            return $license->fresh(['licenseType', 'application', 'issuedBy', 'previousLicense']);
        });
    }

    public function renew(User $citizen, int $licenseId): License
    {
        return DB::transaction(function () use ($citizen, $licenseId) {
            $old = $this->requireOwnedRenewableLicense($citizen, $licenseId);
            $old = License::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
            $this->transitions->assertCanBecomeSuccessor($old);

            $issueDate = now()->toDateString();
            $expiryDate = now()->addYears((int) config('license.validity_years', 10))->toDateString();

            $newLicense = $this->licenses->create([
                'license_number' => $this->licenses->generateUniqueLicenseNumber(),
                'citizen_id' => $old->citizen_id,
                'license_type_id' => $old->license_type_id,
                'application_id' => $old->application_id,
                'issued_by' => null,
                'previous_license_id' => $old->id,
                'status' => LicenseStatus::Active,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'verification_token' => $this->lifecycle->generateVerificationToken(),
                'print_count' => 0,
            ]);

            $from = $old->status;
            $old->status = LicenseStatus::Renewed;
            $old->save();

            $this->lifecycle->recordHistory(
                $old,
                'renewed',
                $from,
                LicenseStatus::Renewed,
                $citizen,
                null,
                'citizen_renew',
                ['successor_license_id' => $newLicense->id]
            );

            $this->lifecycle->recordHistory(
                $newLicense,
                'issued',
                null,
                LicenseStatus::Active,
                $citizen,
                null,
                'citizen_renew',
                ['previous_license_id' => $old->id]
            );

            $this->lifecycle->recordAudit(
                $citizen,
                'license.renewed',
                $newLicense,
                null,
                [
                    'license_number' => $newLicense->license_number,
                    'previous_license_id' => $old->id,
                    'status' => LicenseStatus::Active->value,
                ]
            );

            return $newLicense->fresh(['licenseType', 'application', 'previousLicense']);
        });
    }

    public function replace(User $citizen, int $licenseId, string $replacementType): License
    {
        if (! in_array($replacementType, ['lost', 'damaged'], true)) {
            throw new ApiException('messages.licenses.replacement_type_invalid', 422);
        }

        return DB::transaction(function () use ($citizen, $licenseId, $replacementType) {
            $old = $this->licenses->findOwnedByCitizen($citizen, $licenseId);

            if ($old === null) {
                throw new ApiException('messages.licenses.not_found', 404);
            }

            $old = License::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();

            if ($old->status === LicenseStatus::Blocked) {
                throw new ApiException('messages.licenses.blocked_cannot_replace', 422);
            }

            if (! in_array($old->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
                throw new ApiException('messages.licenses.cannot_replace_status', 422);
            }

            $this->assertCitizenHasNoUnpaidFines($citizen->id);
            $this->transitions->assertCanBecomeSuccessor($old);

            $newLicense = $this->licenses->create([
                'license_number' => $this->licenses->generateUniqueLicenseNumber(),
                'citizen_id' => $old->citizen_id,
                'license_type_id' => $old->license_type_id,
                'application_id' => $old->application_id,
                'issued_by' => null,
                'previous_license_id' => $old->id,
                'status' => LicenseStatus::Active,
                'issue_date' => now()->toDateString(),
                'expiry_date' => $old->expiry_date,
                'verification_token' => $this->lifecycle->generateVerificationToken(),
                'print_count' => 0,
            ]);

            $from = $old->status;
            $old->status = LicenseStatus::Inactive;
            $old->save();

            $this->lifecycle->recordHistory(
                $old,
                'replaced',
                $from,
                LicenseStatus::Inactive,
                $citizen,
                null,
                'citizen_replacement',
                ['successor_license_id' => $newLicense->id, 'replacement_type' => $replacementType]
            );

            $this->lifecycle->recordHistory(
                $newLicense,
                'issued',
                null,
                LicenseStatus::Active,
                $citizen,
                null,
                'citizen_replacement',
                ['previous_license_id' => $old->id, 'replacement_type' => $replacementType]
            );

            $this->lifecycle->recordAudit(
                $citizen,
                'license.replaced',
                $newLicense,
                null,
                [
                    'license_number' => $newLicense->license_number,
                    'previous_license_id' => $old->id,
                    'replacement_type' => $replacementType,
                ]
            );

            return $newLicense->fresh(['licenseType', 'application', 'previousLicense']);
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
            'message' => \App\Support\CitizenMessageTranslator::get('messages.licenses.unblock_registered'),
        ];
    }

    public function block(User $actor, int $licenseId, ?string $reason = null): License
    {
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        if ($reason === null) {
            throw new ApiException('messages.licenses.block_reason_required', 422);
        }

        if (mb_strlen($reason) > 1000) {
            throw new ApiException('messages.licenses.block_reason_too_long', 422);
        }

        return DB::transaction(function () use ($actor, $licenseId, $reason) {
            $license = License::query()->whereKey($licenseId)->lockForUpdate()->first();

            if ($license === null) {
                throw new ApiException('messages.licenses.not_found', 404);
            }

            if ($license->status === LicenseStatus::Blocked) {
                return $license->fresh(['licenseType', 'application', 'citizen', 'blockedBy']);
            }

            $this->transitions->assertCanBlock($license);

            $previousStatus = $license->status;
            $license->status = LicenseStatus::Blocked;
            $license->blocked_at = now();
            $license->blocked_by = $actor->id;
            $license->block_reason = $reason;
            $license->save();

            $this->lifecycle->recordHistory(
                $license,
                'blocked',
                $previousStatus,
                LicenseStatus::Blocked,
                $actor,
                $reason,
                'dashboard',
            );

            $this->lifecycle->recordAudit(
                $actor,
                'license.blocked',
                $license,
                ['status' => $previousStatus->value],
                [
                    'status' => LicenseStatus::Blocked->value,
                    'block_reason' => $reason,
                    'blocked_by' => $actor->id,
                ]
            );

            $this->notifications->sendToUser(
                $license->citizen_id,
                Msg::get('notifications.license_blocked_title'),
                Msg::get('notifications.license_blocked_body'),
                'license.blocked',
                ['license_id' => $license->id, 'license_number' => $license->license_number]
            );

            return $license->fresh(['licenseType', 'application', 'citizen', 'blockedBy']);
        });
    }

    public function unblock(User $actor, int $licenseId): License
    {
        return DB::transaction(function () use ($actor, $licenseId) {
            $license = License::query()->whereKey($licenseId)->lockForUpdate()->first();

            if ($license === null) {
                throw new ApiException('messages.licenses.not_found', 404);
            }

            $this->transitions->assertCanUnblock($license);

            if ($this->citizenHasUnpaidFines($license->citizen_id)) {
                throw new ApiException('messages.licenses.unpaid_fines_unblock', 422);
            }

            $previousReason = $license->block_reason;
            $newStatus = $this->transitions->resolveUnblockStatus($license);

            $license->status = $newStatus;
            $license->blocked_at = null;
            $license->blocked_by = null;
            $license->block_reason = null;
            $license->save();

            $this->lifecycle->recordHistory(
                $license,
                'unblocked',
                LicenseStatus::Blocked,
                $newStatus,
                $actor,
                $previousReason,
                'dashboard',
                ['cleared_block_reason' => $previousReason]
            );

            $this->lifecycle->recordAudit(
                $actor,
                'license.unblocked',
                $license,
                ['status' => LicenseStatus::Blocked->value],
                ['status' => $newStatus->value]
            );

            $this->notifications->sendToUser(
                $license->citizen_id,
                Msg::get('notifications.license_unblocked_title'),
                Msg::get('notifications.license_unblocked_body'),
                'license.unblocked',
                ['license_id' => $license->id]
            );

            return $license->fresh(['licenseType', 'application', 'citizen']);
        });
    }

    public function recordPrint(User $actor, License $license): License
    {
        return DB::transaction(function () use ($actor, $license) {
            $locked = License::query()->whereKey($license->id)->lockForUpdate()->firstOrFail();

            $locked->print_count = (int) $locked->print_count + 1;
            $locked->printed_at = now();
            $locked->printed_by = $actor->id;
            $locked->save();

            $this->lifecycle->recordHistory(
                $locked,
                'printed',
                $locked->status,
                $locked->status,
                $actor,
                null,
                'dashboard',
                ['print_count' => $locked->print_count]
            );

            $this->lifecycle->recordAudit(
                $actor,
                'license.printed',
                $locked,
                null,
                [
                    'print_count' => $locked->print_count,
                    'printed_by' => $actor->id,
                ]
            );

            return $locked->fresh(['licenseType', 'application', 'citizen', 'printedBy']);
        });
    }

    private function issueNewLicense(LicenseApplication $application, User $employee): License
    {
        $issueDate = now()->toDateString();
        $expiryDate = now()->addYears((int) config('license.validity_years', 10))->toDateString();

        $license = $this->licenses->create([
            'license_number' => $this->licenses->generateUniqueLicenseNumber(),
            'citizen_id' => $application->citizen_id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application->id,
            'issued_by' => $employee->id,
            'previous_license_id' => null,
            'status' => LicenseStatus::Active,
            'issue_date' => $issueDate,
            'expiry_date' => $expiryDate,
            'verification_token' => $this->lifecycle->generateVerificationToken(),
            'print_count' => 0,
        ]);

        $this->lifecycle->recordHistory(
            $license,
            'issued',
            null,
            LicenseStatus::Active,
            $employee,
            null,
            'issuance',
            ['application_id' => $application->id]
        );

        return $license;
    }

    private function issueRenewalLicense(LicenseApplication $application, User $employee): License
    {
        $old = $this->requireRelatedLicense($application);
        $old = License::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
        $this->transitions->assertCanBecomeSuccessor($old);

        $issueDate = now()->toDateString();
        $expiryDate = now()->addYears((int) config('license.validity_years', 10))->toDateString();

        $license = $this->licenses->create([
            'license_number' => $this->licenses->generateUniqueLicenseNumber(),
            'citizen_id' => $application->citizen_id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application->id,
            'issued_by' => $employee->id,
            'previous_license_id' => $old->id,
            'status' => LicenseStatus::Active,
            'issue_date' => $issueDate,
            'expiry_date' => $expiryDate,
            'verification_token' => $this->lifecycle->generateVerificationToken(),
            'print_count' => 0,
        ]);

        $from = $old->status;
        $old->status = LicenseStatus::Renewed;
        $old->save();

        $this->lifecycle->recordHistory(
            $old,
            'renewed',
            $from,
            LicenseStatus::Renewed,
            $employee,
            null,
            'issuance',
            ['successor_license_id' => $license->id, 'application_id' => $application->id]
        );

        $this->lifecycle->recordHistory(
            $license,
            'issued',
            null,
            LicenseStatus::Active,
            $employee,
            null,
            'issuance',
            ['previous_license_id' => $old->id, 'application_id' => $application->id]
        );

        return $license;
    }

    private function issueReplacementLicense(LicenseApplication $application, ServiceCode $serviceCode, User $employee): License
    {
        $old = $this->requireRelatedLicense($application);
        $old = License::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
        $this->transitions->assertCanBecomeSuccessor($old);

        $license = $this->licenses->create([
            'license_number' => $this->licenses->generateUniqueLicenseNumber(),
            'citizen_id' => $application->citizen_id,
            'license_type_id' => $application->license_type_id,
            'application_id' => $application->id,
            'issued_by' => $employee->id,
            'previous_license_id' => $old->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => $old->expiry_date,
            'verification_token' => $this->lifecycle->generateVerificationToken(),
            'print_count' => 0,
        ]);

        $from = $old->status;
        $old->status = LicenseStatus::Inactive;
        $old->save();

        $this->lifecycle->recordHistory(
            $old,
            'replaced',
            $from,
            LicenseStatus::Inactive,
            $employee,
            null,
            'issuance',
            [
                'successor_license_id' => $license->id,
                'application_id' => $application->id,
                'service_code' => $serviceCode->value,
            ]
        );

        $this->lifecycle->recordHistory(
            $license,
            'issued',
            null,
            LicenseStatus::Active,
            $employee,
            null,
            'issuance',
            [
                'previous_license_id' => $old->id,
                'application_id' => $application->id,
                'service_code' => $serviceCode->value,
            ]
        );

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
