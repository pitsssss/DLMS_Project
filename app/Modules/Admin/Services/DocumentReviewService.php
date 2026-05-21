<?php

namespace App\Modules\Admin\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\ApiException;
use App\Models\ApplicationDocument;
use App\Models\LicenseApplication;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Notifications\Services\NotificationService;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DocumentReviewService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications
    ) {}

    /**
     * @return LengthAwarePaginator<int, ApplicationDocument>
     */
    public function paginatePending(User $reviewer, int $perPage): LengthAwarePaginator
    {
        return ApplicationDocument::query()
            ->where('status', DocumentStatus::PendingReview)
            ->whereHas('application', function ($q): void {
                $q->where('status', ApplicationStatus::DocumentsUnderReview);
            })
            ->with(['application', 'requiredDocument'])
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    public function approve(User $reviewer, int $documentId): ApplicationDocument
    {
        $document = $this->findReviewableDocument($documentId);

        return DB::transaction(function () use ($document, $reviewer) {
            $document->status = DocumentStatus::Approved;
            $document->rejection_reason = null;
            $document->reviewed_by = $reviewer->id;
            $document->reviewed_at = now();
            $document->save();

            $application = $document->application()->with('licenseType', 'serviceType', 'currentTestType')->firstOrFail();

            if ($this->allRequiredDocumentsApproved($application)) {
                $this->applications->transitionStatus(
                    $application,
                    ApplicationStatus::PaymentPending,
                    $reviewer,
                    'All required documents approved.'
                );
            }

            $this->auditLogs->log(
                $reviewer,
                'document.approved',
                'application_document',
                $document->id,
                ['status' => DocumentStatus::PendingReview->value],
                ['status' => DocumentStatus::Approved->value]
            );

            $this->notifications->sendToUser(
                $application->citizen_id,
                'Document approved',
                'A submitted document was approved.',
                'document.approved',
                ['document_id' => $document->id, 'application_id' => $application->id]
            );

            return $document->fresh(['requiredDocument', 'application']);
        });
    }

    public function reject(User $reviewer, int $documentId, string $rejectionReason): ApplicationDocument
    {
        $document = $this->findReviewableDocument($documentId);

        return DB::transaction(function () use ($document, $reviewer, $rejectionReason) {
            $document->status = DocumentStatus::Rejected;
            $document->rejection_reason = $rejectionReason;
            $document->reviewed_by = $reviewer->id;
            $document->reviewed_at = now();
            $document->save();

            $application = $document->application;

            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::DocumentsRejected,
                $reviewer,
                'One or more documents were rejected.',
                $rejectionReason
            );

            $this->auditLogs->log(
                $reviewer,
                'document.rejected',
                'application_document',
                $document->id,
                ['status' => DocumentStatus::PendingReview->value],
                ['status' => DocumentStatus::Rejected->value, 'rejection_reason' => $rejectionReason]
            );

            return $document->fresh(['requiredDocument', 'application']);
        });
    }

    private function findReviewableDocument(int $documentId): ApplicationDocument
    {
        $document = ApplicationDocument::query()
            ->whereKey($documentId)
            ->with('application')
            ->first();

        if ($document === null) {
            throw new ApiException('Document not found.', 404);
        }

        if ($document->application->status !== ApplicationStatus::DocumentsUnderReview) {
            throw new ApiException('This document is not awaiting review.', 422);
        }

        if ($document->status !== DocumentStatus::PendingReview) {
            throw new ApiException('This document has already been reviewed.', 422);
        }

        return $document;
    }

    private function allRequiredDocumentsApproved(LicenseApplication $application): bool
    {
        $required = $this->requiredDocumentsForApplication($application)->where('is_required', true);

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

    /**
     * @return Collection<int, RequiredDocument>
     */
    private function requiredDocumentsForApplication(LicenseApplication $application): Collection
    {
        return RequiredDocument::query()
            ->where('is_active', true)
            ->where(function ($q) use ($application): void {
                $q->whereNull('license_type_id')
                    ->orWhere('license_type_id', $application->license_type_id);
            })
            ->where(function ($q) use ($application): void {
                $q->whereNull('service_type_id')
                    ->orWhere('service_type_id', $application->service_type_id);
            })
            ->orderBy('name')
            ->get();
    }
}
