<?php

namespace App\Modules\Licenses\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\ApplicationDocument;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Modules\Appointments\Services\TestProgressionService;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Licenses\Repositories\LicenseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LicenseService
{
    public function __construct(
        private readonly LicenseRepository $licenses,
        private readonly ApplicationRepository $applications,
        private readonly TestProgressionService $progression
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
            throw new ApiException('License not found.', 404);
        }

        return $license;
    }

    public function issueForApplication(User $employee, int $applicationId): License
    {
        return DB::transaction(function () use ($employee, $applicationId) {
            $application = LicenseApplication::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->with(['licenseType', 'serviceType'])
                ->first();

            if ($application === null) {
                throw new ApiException('Application not found.', 404);
            }

            $this->assertApplicationReadyForIssuance($application);

            if ($this->licenses->existsForApplication($application->id)) {
                throw new ApiException('A license has already been issued for this application.', 422);
            }

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

            $application->approved_at ??= now();
            $application->issued_at = now();
            $application->save();

            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::LicenseIssued,
                $employee,
                'Driving license issued.'
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
            throw new ApiException('Replacement type must be lost or damaged.', 422);
        }

        return DB::transaction(function () use ($citizen, $licenseId) {
            $old = $this->licenses->findOwnedByCitizen($citizen, $licenseId);

            if ($old === null) {
                throw new ApiException('License not found.', 404);
            }

            if ($old->status === LicenseStatus::Blocked) {
                throw new ApiException('Blocked licenses cannot be replaced.', 422);
            }

            if (! in_array($old->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
                throw new ApiException('This license cannot be replaced in its current status.', 422);
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
            throw new ApiException('License not found.', 404);
        }

        if ($license->status !== LicenseStatus::Blocked) {
            throw new ApiException('Only blocked licenses can request unblock.', 422);
        }

        if ($this->citizenHasUnpaidFines($citizen->id)) {
            throw new ApiException('All related fines must be paid before requesting unblock.', 422);
        }

        return [
            'license_id' => $license->id,
            'license_number' => $license->license_number,
            'status' => $license->status->value,
            'message' => 'Unblock request registered. An employee will review and process your request.',
        ];
    }

    public function block(User $actor, int $licenseId, ?string $reason = null): License
    {
        return DB::transaction(function () use ($actor, $licenseId, $reason) {
            $license = License::query()->whereKey($licenseId)->lockForUpdate()->first();

            if ($license === null) {
                throw new ApiException('License not found.', 404);
            }

            if ($license->status === LicenseStatus::Blocked) {
                return $license->fresh(['licenseType', 'application', 'citizen']);
            }

            if (! in_array($license->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
                throw new ApiException('This license cannot be blocked in its current status.', 422);
            }

            $license->status = LicenseStatus::Blocked;
            $license->save();

            return $license->fresh(['licenseType', 'application', 'citizen']);
        });
    }

    public function unblock(User $actor, int $licenseId): License
    {
        return DB::transaction(function () use ($actor, $licenseId) {
            $license = License::query()->whereKey($licenseId)->lockForUpdate()->first();

            if ($license === null) {
                throw new ApiException('License not found.', 404);
            }

            if ($license->status !== LicenseStatus::Blocked) {
                throw new ApiException('Only blocked licenses can be unblocked.', 422);
            }

            if ($this->citizenHasUnpaidFines($license->citizen_id)) {
                throw new ApiException('Citizen has unpaid fines. Fines must be settled before unblock.', 422);
            }

            $license->status = $license->expiry_date->isPast()
                ? LicenseStatus::Expired
                : LicenseStatus::Active;
            $license->save();

            return $license->fresh(['licenseType', 'application', 'citizen']);
        });
    }

    private function assertApplicationReadyForIssuance(LicenseApplication $application): void
    {
        if ($application->status !== ApplicationStatus::Approved) {
            throw new ApiException('Application must be approved before a license can be issued.', 422);
        }

        if (! $this->progression->allRequiredTestsPassed($application)) {
            throw new ApiException('All required tests must be passed before issuing a license.', 422);
        }

        if (! $this->applicationFeePaid($application)) {
            throw new ApiException('Application fee payment must be completed before issuing a license.', 422);
        }

        if (! $this->allRequiredDocumentsApproved($application)) {
            throw new ApiException('All required documents must be approved before issuing a license.', 422);
        }

        if ($this->citizenHasUnpaidFines($application->citizen_id)) {
            throw new ApiException('Citizen has unpaid fines. Fines must be settled before license issuance.', 422);
        }
    }

    private function applicationFeePaid(LicenseApplication $application): bool
    {
        return Payment::query()
            ->where('application_id', $application->id)
            ->where('status', PaymentStatus::Completed)
            ->whereHas('fee', fn ($q) => $q->where('code', 'application_fee'))
            ->exists();
    }

    private function allRequiredDocumentsApproved(LicenseApplication $application): bool
    {
        $required = RequiredDocument::query()
            ->where('is_active', true)
            ->where(function ($q) use ($application): void {
                $q->whereNull('license_type_id')
                    ->orWhere('license_type_id', $application->license_type_id);
            })
            ->where(function ($q) use ($application): void {
                $q->whereNull('service_type_id')
                    ->orWhere('service_type_id', $application->service_type_id);
            })
            ->where('is_required', true)
            ->get();

        foreach ($required as $rd) {
            $latest = ApplicationDocument::query()
                ->where('application_id', $application->id)
                ->where('required_document_id', $rd->id)
                ->orderByDesc('id')
                ->first();

            if ($latest === null || $latest->status !== DocumentStatus::Approved) {
                return false;
            }
        }

        return true;
    }

    private function requireOwnedRenewableLicense(User $citizen, int $licenseId): License
    {
        $license = $this->licenses->findOwnedByCitizen($citizen, $licenseId);

        if ($license === null) {
            throw new ApiException('License not found.', 404);
        }

        if (! in_array($license->status, [LicenseStatus::Active, LicenseStatus::Expired], true)) {
            throw new ApiException('This license cannot be renewed in its current status.', 422);
        }

        $graceDays = (int) config('license.renewal_grace_days', 90);
        $renewableFrom = $license->expiry_date->copy()->subDays($graceDays);

        if (now()->toDateString() < $renewableFrom->toDateString() && $license->status === LicenseStatus::Active) {
            throw new ApiException('License is not yet eligible for renewal.', 422);
        }

        $this->assertCitizenHasNoUnpaidFines($citizen->id);

        return $license;
    }

    private function assertCitizenHasNoUnpaidFines(int $citizenId): void
    {
        if ($this->citizenHasUnpaidFines($citizenId)) {
            throw new ApiException('Unpaid fines must be settled before continuing.', 422);
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
