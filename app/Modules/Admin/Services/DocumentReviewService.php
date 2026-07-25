<?php

namespace App\Modules\Admin\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentRejectionReason;
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
        $result = DB::transaction(function () use ($documentId, $reviewer): array {
            [$document, $application] = $this->lockReviewableDocument($documentId);

            $oldStatus = $document->status->value;

            $document->status = DocumentStatus::Approved;
            $document->rejection_reason = null;
            $document->rejection_reason_code = null;
            $document->rejection_details = null;
            $document->reviewed_by = $reviewer->id;
            $document->reviewed_at = now();
            $document->save();

            $application->loadMissing(['licenseType', 'serviceType', 'currentTestType']);

            if ($this->allRequiredDocumentsApproved($application)) {
                $this->applications->transitionStatus(
                    $application,
                    ApplicationStatus::PaymentPending,
                    $reviewer,
                    __('messages.documents.note_all_approved')
                );
            }

            $this->auditLogs->log(
                $reviewer,
                'document.approved',
                'application_document',
                $document->id,
                ['status' => $oldStatus],
                ['status' => DocumentStatus::Approved->value]
            );

            $fresh = $document->fresh(['requiredDocument', 'application']);

            return [
                'document' => $fresh,
                'notification' => [
                    'user_id' => $application->citizen_id,
                    'title' => __('messages.notifications.document_approved_title'),
                    'body' => __('messages.notifications.document_approved_body'),
                    'type' => 'document.approved',
                    'data' => [
                        'document_id' => $document->id,
                        'application_id' => $application->id,
                    ],
                ],
            ];
        });

        $this->dispatchNotification($result['notification']);

        return $result['document'];
    }

    public function reject(
        User $reviewer,
        int $documentId,
        DocumentRejectionReason $reason,
        ?string $details = null
    ): ApplicationDocument {
        $details = $details !== null ? trim($details) : null;
        if ($details === '') {
            $details = null;
        }

        if ($reason->requiresDetails() && $details === null) {
            throw new ApiException('messages.documents.rejection_details_required', 422);
        }

        $displayReason = $reason->displayReason($details);

        $result = DB::transaction(function () use ($documentId, $reviewer, $reason, $details, $displayReason): array {
            [$document, $application] = $this->lockReviewableDocument($documentId);

            $oldStatus = $document->status->value;

            $document->status = DocumentStatus::Rejected;
            $document->rejection_reason_code = $reason->value;
            $document->rejection_details = $details;
            $document->rejection_reason = $displayReason;
            $document->reviewed_by = $reviewer->id;
            $document->reviewed_at = now();
            $document->save();

            $this->applications->transitionStatus(
                $application,
                ApplicationStatus::DocumentsRejected,
                $reviewer,
                __('messages.documents.note_some_rejected'),
                $displayReason
            );

            $this->auditLogs->log(
                $reviewer,
                'document.rejected',
                'application_document',
                $document->id,
                ['status' => $oldStatus],
                [
                    'status' => DocumentStatus::Rejected->value,
                    'rejection_reason_code' => $reason->value,
                    'rejection_reason_label' => $reason->label(),
                ]
            );

            $fresh = $document->fresh(['requiredDocument', 'application']);
            $documentName = $fresh?->requiredDocument?->name ?? __('messages.documents.generic_document_name');
            $applicationNumber = $application->application_number;

            $detailsSuffix = $details !== null
                ? ' '.__('messages.notifications.document_rejected_details', ['details' => $details])
                : '';

            return [
                'document' => $fresh,
                'notification' => [
                    'user_id' => $application->citizen_id,
                    'title' => __('messages.notifications.document_rejected_title'),
                    'body' => __('messages.notifications.document_rejected_body', [
                        'document_name' => $documentName,
                        'application_number' => $applicationNumber,
                        'reason' => $reason->label(),
                        'details_suffix' => $detailsSuffix,
                    ]),
                    'type' => 'document.rejected',
                    'data' => [
                        'document_id' => $document->id,
                        'application_id' => $application->id,
                        'rejection_reason_code' => $reason->value,
                        'rejection_reason_label' => $reason->label(),
                        'rejection_details' => $details,
                    ],
                ],
            ];
        });

        $this->dispatchNotification($result['notification']);

        return $result['document'];
    }

    /**
     * Legacy admin free-text rejection → canonical structured decision.
     */
    public function rejectFromLegacyReason(User $reviewer, int $documentId, string $legacyReason): ApplicationDocument
    {
        $trimmed = trim($legacyReason);

        if ($trimmed === '') {
            throw new ApiException('messages.documents.rejection_details_required', 422);
        }

        $known = DocumentRejectionReason::tryFrom($trimmed);

        if ($known !== null && $known !== DocumentRejectionReason::Other) {
            return $this->reject($reviewer, $documentId, $known, null);
        }

        return $this->reject($reviewer, $documentId, DocumentRejectionReason::Other, $trimmed);
    }

    /**
     * @return array{0: ApplicationDocument, 1: LicenseApplication}
     */
    private function lockReviewableDocument(int $documentId): array
    {
        $document = ApplicationDocument::query()
            ->whereKey($documentId)
            ->lockForUpdate()
            ->first();

        if ($document === null) {
            throw new ApiException('messages.documents.review_not_found', 404);
        }

        $application = LicenseApplication::query()
            ->whereKey($document->application_id)
            ->lockForUpdate()
            ->first();

        if ($application === null) {
            throw new ApiException('messages.documents.review_not_found', 404);
        }

        if ($application->status !== ApplicationStatus::DocumentsUnderReview) {
            throw new ApiException('messages.documents.not_awaiting_review', 422);
        }

        if ($document->status !== DocumentStatus::PendingReview) {
            throw new ApiException('messages.documents.already_reviewed', 422);
        }

        if (! $this->isLatestActiveVersion($document)) {
            throw new ApiException('messages.documents.not_latest_version', 422);
        }

        return [$document, $application];
    }

    private function isLatestActiveVersion(ApplicationDocument $document): bool
    {
        $latestId = ApplicationDocument::query()
            ->where('application_id', $document->application_id)
            ->where('required_document_id', $document->required_document_id)
            ->orderByDesc('id')
            ->value('id');

        return $latestId !== null && (int) $latestId === (int) $document->id;
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

    /**
     * @param  array{user_id: int, title: string, body: string, type: string, data: array<string, mixed>}|null  $notification
     */
    private function dispatchNotification(?array $notification): void
    {
        if ($notification === null) {
            return;
        }

        try {
            $this->notifications->sendToUser(
                $notification['user_id'],
                $notification['title'],
                $notification['body'],
                $notification['type'],
                $notification['data']
            );
        } catch (\Throwable) {
            // Review is already committed; notification failure must not roll it back.
        }
    }
}
