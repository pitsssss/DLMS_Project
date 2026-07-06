<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\ApiException;
use App\Models\ApplicationDocument;
use App\Models\LicenseApplication;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Modules\Admin\Services\DocumentReviewService as AdminDocumentReviewService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class DashboardDocumentReviewService
{
    public function __construct(
        private readonly AdminDocumentReviewService $adminReviews,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LicenseApplication>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = LicenseApplication::query()
            ->with(['citizen', 'licenseType', 'serviceType', 'applicationDocuments.requiredDocument'])
            ->whereIn('status', [
                ApplicationStatus::DocumentsUnderReview,
                ApplicationStatus::DocumentsRejected,
                ApplicationStatus::PaymentPending,
                ApplicationStatus::PaymentCompleted,
                ApplicationStatus::AppointmentPending,
                ApplicationStatus::InTesting,
                ApplicationStatus::WaitingRetest,
                ApplicationStatus::Approved,
                ApplicationStatus::AdministrativeReview,
                ApplicationStatus::LicenseIssued,
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('application_number', 'like', '%'.$search.'%')
                    ->orWhereHas('citizen', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%')
                            ->orWhere('national_id', 'like', '%'.$search.'%');
                    });
            });
        }

        if (! empty($filters['service_type_code'])) {
            $code = trim((string) $filters['service_type_code']);
            $query->whereHas('serviceType', fn ($q) => $q->where('code', $code));
        }

        if (! empty($filters['license_type_code'])) {
            $code = trim((string) $filters['license_type_code']);
            $query->whereHas('licenseType', fn ($q) => $q->where('code', $code));
        }

        if (! empty($filters['review_status'])) {
            $this->applyReviewStatusFilter($query, (string) $filters['review_status']);
        }

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->each(fn (LicenseApplication $application) => $this->attachChecklist($application));

        return $paginator;
    }

    public function stats(): array
    {
        return [
            'awaiting_review' => LicenseApplication::query()
                ->where('status', ApplicationStatus::DocumentsUnderReview)
                ->count(),
            'completed_documents' => LicenseApplication::query()
                ->whereIn('status', [
                    ApplicationStatus::PaymentPending,
                    ApplicationStatus::PaymentCompleted,
                    ApplicationStatus::AppointmentPending,
                    ApplicationStatus::InTesting,
                    ApplicationStatus::WaitingRetest,
                    ApplicationStatus::Approved,
                    ApplicationStatus::AdministrativeReview,
                    ApplicationStatus::LicenseIssued,
                ])
                ->count(),
            'late_requests' => $this->lateApplicationsQuery()->count(),
            'reupload_required' => LicenseApplication::query()
                ->where('status', ApplicationStatus::DocumentsRejected)
                ->count(),
        ];
    }

    public function getApplication(int $applicationId): LicenseApplication
    {
        $application = LicenseApplication::query()
            ->with(['citizen', 'licenseType', 'serviceType', 'applicationDocuments.requiredDocument'])
            ->whereKey($applicationId)
            ->first();

        if ($application === null) {
            throw new ApiException('messages.dashboard.application_not_found', 404);
        }

        $this->attachChecklist($application);

        return $application;
    }

    public function approve(User $reviewer, int $documentId): ApplicationDocument
    {
        return $this->adminReviews->approve($reviewer, $documentId);
    }

    public function reject(User $reviewer, int $documentId, string $reason): ApplicationDocument
    {
        return $this->adminReviews->reject($reviewer, $documentId, $reason);
    }

    public function getPreviewDocument(int $documentId): ApplicationDocument
    {
        $document = ApplicationDocument::query()
            ->whereKey($documentId)
            ->with('application')
            ->first();

        if ($document === null || ! Storage::disk('local')->exists($document->file_path)) {
            throw new ApiException('messages.documents.review_not_found', 404);
        }

        return $document;
    }

    private function attachChecklist(LicenseApplication $application): void
    {
        $application->setRelation(
            'dashboardDocumentReviewChecklist',
            $this->documentChecklist($application)
        );
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
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function documentChecklist(LicenseApplication $application): \Illuminate\Support\Collection
    {
        return $this->requiredDocumentsForApplication($application)
            ->map(function (RequiredDocument $required) use ($application): array {
                $latest = $application->applicationDocuments
                    ->where('required_document_id', $required->id)
                    ->sortByDesc('id')
                    ->first();

                return [
                    'required_document' => $required,
                    'latest_document' => $latest,
                    'is_uploaded' => $latest !== null,
                    'is_approved' => $latest?->status === DocumentStatus::Approved,
                    'is_pending_review' => $latest?->status === DocumentStatus::PendingReview,
                    'is_rejected' => $latest?->status === DocumentStatus::Rejected,
                ];
            })
            ->values();
    }

    private function applyReviewStatusFilter($query, string $status): void
    {
        match ($status) {
            'awaiting_review' => $query->where('status', ApplicationStatus::DocumentsUnderReview),
            'reupload_required' => $query->where('status', ApplicationStatus::DocumentsRejected),
            'late' => $query->whereKey($this->lateApplicationsQuery()->pluck('id')),
            'completed' => $query->whereIn('status', [
                ApplicationStatus::PaymentPending,
                ApplicationStatus::PaymentCompleted,
                ApplicationStatus::AppointmentPending,
                ApplicationStatus::InTesting,
                ApplicationStatus::WaitingRetest,
                ApplicationStatus::Approved,
                ApplicationStatus::AdministrativeReview,
                ApplicationStatus::LicenseIssued,
            ]),
            default => null,
        };
    }

    private function lateApplicationsQuery()
    {
        $deadline = now()->subDays(3);

        return LicenseApplication::query()
            ->where('status', ApplicationStatus::DocumentsUnderReview)
            ->where(function ($query) use ($deadline): void {
                $query->where('submitted_at', '<=', $deadline)
                    ->orWhere(function ($fallback) use ($deadline): void {
                        $fallback->whereNull('submitted_at')
                            ->where('created_at', '<=', $deadline);
                    });
            });
    }
}
