<?php

namespace App\Modules\Licenses\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceCode;
use App\Enums\TestResultStatus;
use App\Exceptions\ApiException;
use App\Models\ApplicationDocument;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\Payment;
use App\Models\RequiredDocument;
use App\Models\TestType;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Modules\Appointments\Services\TestProgressionService;
use App\Modules\Payments\Support\ApplicationFeeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for license issuance readiness.
 *
 * Explicitly issuable: new_license, renew_license, lost_replacement, damaged_replacement.
 * Excluded: license_unblock (dedicated unblock workflow) and unknown/custom codes.
 */
class LicenseIssuanceEligibilityService
{
    public function __construct(
        private readonly TestProgressionService $progression,
        private readonly ApplicationFeeResolver $feeResolver,
    ) {}

    public function assertReady(LicenseApplication $application): void
    {
        $application->loadMissing('serviceType');
        $code = $application->serviceType?->code;

        if (ServiceWorkflow::usesUnblockWorkflow($code)) {
            throw new ApiException('messages.licenses.use_unblock_endpoint', 422);
        }

        if (! ServiceWorkflow::producesLicense($code)) {
            throw new ApiException('messages.licenses.service_not_issuable', 422);
        }

        if ($application->status !== ApplicationStatus::Approved) {
            throw new ApiException('messages.licenses.must_be_approved', 422);
        }

        if ($this->alreadyIssued($application->id)) {
            throw new ApiException('messages.licenses.already_issued', 422);
        }

        if (! $this->applicationFeePaid($application)) {
            throw new ApiException('messages.licenses.payment_required', 422);
        }

        if (! $this->allRequiredDocumentsApproved($application)) {
            throw new ApiException('messages.licenses.documents_required', 422);
        }

        if (ServiceWorkflow::requiresTests($code)
            && ! $this->progression->allRequiredTestsPassed($application)) {
            throw new ApiException('messages.licenses.tests_required', 422);
        }

        if ($this->citizenHasUnpaidFines($application->citizen_id)) {
            throw new ApiException('messages.licenses.unpaid_fines_issue', 422);
        }
    }

    public function isReady(LicenseApplication $application): bool
    {
        try {
            $this->assertReady($application);

            return true;
        } catch (ApiException) {
            return false;
        }
    }

    /**
     * Efficient countable query matching {@see assertReady()}.
     *
     * @return Builder<LicenseApplication>
     */
    public function eligibleQuery(): Builder
    {
        $issuableCodes = ServiceWorkflow::issuableCodes();
        $requiredTestTypeIds = TestType::query()
            ->where('is_required', true)
            ->where('is_active', true)
            ->orderBy('sequence_order')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $approvedDocument = DocumentStatus::Approved->value;
        $completedPayment = PaymentStatus::Completed->value;
        $passedResult = TestResultStatus::Passed->value;
        $unpaidFine = FineStatus::Unpaid->value;

        $applications = LicenseApplication::query()
            ->where('license_applications.status', ApplicationStatus::Approved)
            ->whereHas('serviceType', function (Builder $service) use ($issuableCodes): void {
                $service->whereIn('code', $issuableCodes);
            })
            ->whereDoesntHave('license')
            ->whereDoesntHave('citizen.fines', function (Builder $fines) use ($unpaidFine): void {
                $fines->where('status', $unpaidFine);
            });

        $applications->whereExists(function ($sub) use ($completedPayment): void {
            $sub->select(DB::raw(1))
                ->from('payments')
                ->join('fees', 'fees.id', '=', 'payments.fee_id')
                ->join('service_types', 'service_types.id', '=', 'license_applications.service_type_id')
                ->whereColumn('payments.application_id', 'license_applications.id')
                ->where('payments.status', $completedPayment)
                ->whereRaw(
                    "fees.code = CASE service_types.code
                        WHEN 'renew_license' THEN 'renewal_fee'
                        WHEN 'lost_replacement' THEN 'lost_replacement_fee'
                        WHEN 'damaged_replacement' THEN 'damaged_replacement_fee'
                        ELSE 'application_fee'
                    END"
                );
        });

        $applications->whereRaw(
            'NOT EXISTS (
                SELECT 1 FROM required_documents rd
                WHERE rd.is_active = 1
                  AND rd.is_required = 1
                  AND (rd.license_type_id IS NULL OR rd.license_type_id = license_applications.license_type_id)
                  AND (rd.service_type_id IS NULL OR rd.service_type_id = license_applications.service_type_id)
                  AND COALESCE((
                    SELECT ad.status
                    FROM application_documents ad
                    WHERE ad.application_id = license_applications.id
                      AND ad.required_document_id = rd.id
                      AND ad.deleted_at IS NULL
                    ORDER BY ad.id DESC
                    LIMIT 1
                  ), \'\') <> ?
            )',
            [$approvedDocument]
        );

        // Tests required only for new_license among issuable codes.
        $applications->where(function (Builder $outer) use ($requiredTestTypeIds, $passedResult): void {
            $outer->whereHas('serviceType', function (Builder $service): void {
                $service->whereIn('code', [
                    ServiceCode::RenewLicense->value,
                    ServiceCode::LostReplacement->value,
                    ServiceCode::DamagedReplacement->value,
                ]);
            });

            if ($requiredTestTypeIds === []) {
                return;
            }

            $outer->orWhere(function (Builder $needsTests) use ($requiredTestTypeIds, $passedResult): void {
                $needsTests->whereHas('serviceType', function (Builder $service): void {
                    $service->where('code', ServiceCode::NewLicense->value);
                });

                foreach ($requiredTestTypeIds as $testTypeId) {
                    $needsTests->whereExists(function ($sub) use ($testTypeId, $passedResult): void {
                        $sub->select(DB::raw(1))
                            ->from('test_results')
                            ->whereColumn('test_results.application_id', 'license_applications.id')
                            ->where('test_results.test_type_id', $testTypeId)
                            ->where('test_results.result', $passedResult);
                    });
                }
            });
        });

        return $applications;
    }

    public function readyCount(): int
    {
        return (int) $this->eligibleQuery()->count();
    }

    public function applicationFeePaid(LicenseApplication $application): bool
    {
        $feeCode = $this->feeResolver->feeCodeForApplication($application);

        return Payment::query()
            ->where('application_id', $application->id)
            ->where('status', PaymentStatus::Completed)
            ->whereHas('fee', fn ($q) => $q->where('code', $feeCode))
            ->exists();
    }

    public function allRequiredDocumentsApproved(LicenseApplication $application): bool
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

    public function citizenHasUnpaidFines(int $citizenId): bool
    {
        return Fine::query()
            ->where('citizen_id', $citizenId)
            ->where('status', FineStatus::Unpaid)
            ->exists();
    }

    public function alreadyIssued(int $applicationId): bool
    {
        return License::query()->where('application_id', $applicationId)->exists();
    }
}
