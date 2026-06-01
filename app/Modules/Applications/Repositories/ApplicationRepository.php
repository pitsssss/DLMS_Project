<?php

namespace App\Modules\Applications\Repositories;

use App\Enums\ApplicationStatus;
use App\Models\ApplicationStatusHistory;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplicationRepository
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications
    ) {}

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
                'notes' => __('messages.applications.note_draft_created'),
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

    public function findActiveForCitizen(User $citizen, int $licenseTypeId, int $serviceTypeId): ?LicenseApplication
    {
        return LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->where('license_type_id', $licenseTypeId)
            ->where('service_type_id', $serviceTypeId)
            ->whereIn('status', ApplicationStatus::activeValues())
            ->with(['licenseType', 'serviceType', 'currentTestType'])
            ->orderByDesc('id')
            ->first();
    }

    public function findById(int $applicationId): ?LicenseApplication
    {
        return LicenseApplication::query()
            ->whereKey($applicationId)
            ->with(['licenseType', 'serviceType', 'currentTestType', 'citizen'])
            ->first();
    }

    public function transitionStatus(
        LicenseApplication $application,
        ApplicationStatus $newStatus,
        ?User $actor,
        ?string $notes = null,
        ?string $applicationRejectionReason = null
    ): LicenseApplication {
        return DB::transaction(function () use ($application, $newStatus, $actor, $notes, $applicationRejectionReason) {
            $oldStatus = $application->status;

            $application->status = $newStatus;

            if ($newStatus === ApplicationStatus::DocumentsUnderReview) {
                $application->rejection_reason = null;
                $application->submitted_at ??= now();
            }

            if ($newStatus === ApplicationStatus::DocumentsRejected && $applicationRejectionReason !== null) {
                $application->rejection_reason = $applicationRejectionReason;
            }

            if ($newStatus === ApplicationStatus::PaymentPending) {
                $application->rejection_reason = null;
            }

            $application->save();

            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'changed_by' => $actor?->id,
                'reason' => $applicationRejectionReason,
                'notes' => $notes,
            ]);

            $this->auditLogs->log(
                $actor,
                'application.status_changed',
                'license_application',
                $application->id,
                ['status' => $oldStatus->value],
                ['status' => $newStatus->value]
            );

            $this->notifications->notifyApplicationStatusChange($application, $newStatus);

            return $application->fresh(['licenseType', 'serviceType', 'currentTestType']);
        });
    }
}
