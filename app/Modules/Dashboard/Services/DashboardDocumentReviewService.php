<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentRejectionReason;
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
    private const PREVIEW_ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
    ];

    public function __construct(
        private readonly AdminDocumentReviewService $adminReviews,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LicenseApplication>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = LicenseApplication::query()
            ->with(['citizen:id,name', 'licenseType', 'serviceType', 'applicationDocuments.requiredDocument'])
            ->orderByRaw('COALESCE(submitted_at, created_at) asc')
            ->orderBy('id');

        $reviewStatus = (string) ($filters['review_status'] ?? 'awaiting_review');
        $this->applyReviewStatusFilter($query, $reviewStatus);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('application_number', 'like', '%'.$search.'%')
                    ->orWhereHas('citizen', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%'.$search.'%');
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

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->each(fn (LicenseApplication $application) => $this->attachChecklist($application));

        return $paginator;
    }

    /**
     * Units:
     * - awaiting_review: applications in documents_under_review with at least one pending document
     * - completed_documents: applications that have left the document-review stage (post-review statuses)
     * - late_requests: applications awaiting review older than 3 days
     * - reupload_required: applications in documents_rejected
     */
    public function stats(): array
    {
        return [
            'awaiting_review' => LicenseApplication::query()
                ->where('status', ApplicationStatus::DocumentsUnderReview)
                ->whereHas('applicationDocuments', fn ($q) => $q->where('status', DocumentStatus::PendingReview))
                ->count(),
            'completed_documents' => LicenseApplication::query()
                ->whereIn('status', $this->completedStatuses())
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
            ->with(['citizen:id,name', 'licenseType', 'serviceType', 'applicationDocuments.requiredDocument'])
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

    public function reject(
        User $reviewer,
        int $documentId,
        DocumentRejectionReason $reason,
        ?string $details = null
    ): ApplicationDocument {
        return $this->adminReviews->reject($reviewer, $documentId, $reason, $details);
    }

    public function getPreviewDocument(int $documentId): ApplicationDocument
    {
        $document = ApplicationDocument::query()
            ->whereKey($documentId)
            ->with('application')
            ->first();

        if ($document === null || $document->application === null) {
            throw new ApiException('messages.documents.review_not_found', 404);
        }

        if ($this->resolvePreviewFilePath($document->file_path) === null) {
            throw new ApiException('messages.documents.preview_unavailable', 404);
        }

        if (! $this->isPreviewableMime($document)) {
            throw new ApiException('messages.documents.preview_unsupported_type', 422);
        }

        return $document;
    }

    public function getPreviewFilePath(ApplicationDocument $document): string
    {
        $path = $this->resolvePreviewFilePath($document->file_path);

        if ($path === null) {
            throw new ApiException('messages.documents.preview_unavailable', 404);
        }

        return $path;
    }

    public function resolvePreviewContentType(ApplicationDocument $document): string
    {
        $mime = strtolower(trim((string) $document->mime_type));

        if (in_array($mime, self::PREVIEW_ALLOWED_MIMES, true)) {
            return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
        }

        $path = $this->resolvePreviewFilePath($document->file_path);
        if ($path !== null && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && in_array(strtolower($detected), self::PREVIEW_ALLOWED_MIMES, true)) {
                    return strtolower($detected) === 'image/jpg' ? 'image/jpeg' : strtolower($detected);
                }
            }
        }

        throw new ApiException('messages.documents.preview_unsupported_type', 422);
    }

    public function sanitizedPreviewFilename(ApplicationDocument $document): string
    {
        $name = basename((string) $document->original_name);
        $name = preg_replace('/[^\w.\-\p{L}\p{N} ]+/u', '_', $name) ?: 'document';
        $name = trim($name, " .\t\n\r\0\x0B");

        return $name !== '' ? $name : 'document';
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
            'awaiting_review' => $query
                ->where('status', ApplicationStatus::DocumentsUnderReview)
                ->whereHas(
                    'applicationDocuments',
                    fn ($q) => $q->where('status', DocumentStatus::PendingReview)
                ),
            'reupload_required' => $query->where('status', ApplicationStatus::DocumentsRejected),
            'late' => $query->whereKey($this->lateApplicationsQuery()->pluck('id')),
            'completed' => $query->whereIn('status', $this->completedStatuses()),
            default => null,
        };
    }

    /**
     * @return list<ApplicationStatus>
     */
    private function completedStatuses(): array
    {
        return [
            ApplicationStatus::PaymentPending,
            ApplicationStatus::PaymentCompleted,
            ApplicationStatus::AppointmentPending,
            ApplicationStatus::InTesting,
            ApplicationStatus::WaitingRetest,
            ApplicationStatus::Approved,
            ApplicationStatus::AdministrativeReview,
            ApplicationStatus::LicenseIssued,
        ];
    }

    private function lateApplicationsQuery()
    {
        $deadline = now()->subDays(3);

        return LicenseApplication::query()
            ->where('status', ApplicationStatus::DocumentsUnderReview)
            ->whereHas('applicationDocuments', fn ($q) => $q->where('status', DocumentStatus::PendingReview))
            ->where(function ($query) use ($deadline): void {
                $query->where('submitted_at', '<=', $deadline)
                    ->orWhere(function ($fallback) use ($deadline): void {
                        $fallback->whereNull('submitted_at')
                            ->where('created_at', '<=', $deadline);
                    });
            });
    }

    private function resolvePreviewFilePath(string $filePath): ?string
    {
        $path = trim($filePath, " \t\n\r\0\x0B\"'");

        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $diskRoot = realpath(Storage::disk('local')->path(''));
        if ($diskRoot === false) {
            return null;
        }

        if ($this->isAbsolutePath($path)) {
            $real = realpath($path);
        } else {
            if (str_contains($path, '..')) {
                return null;
            }

            $candidate = Storage::disk('local')->path($path);
            $real = realpath($candidate);
        }

        if ($real === false || ! is_file($real)) {
            return null;
        }

        if (! $this->isPathInsideRoot($real, $diskRoot)) {
            return null;
        }

        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function isPathInsideRoot(string $realPath, string $rootPath): bool
    {
        $real = $this->normalizePath($realPath);
        $root = rtrim($this->normalizePath($rootPath), '/');

        if (PHP_OS_FAMILY === 'Windows') {
            $real = strtolower($real);
            $root = strtolower($root);
        }

        return $real === $root || str_starts_with($real, $root.'/');
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function isPreviewableMime(ApplicationDocument $document): bool
    {
        $mime = strtolower(trim((string) $document->mime_type));

        if (in_array($mime, self::PREVIEW_ALLOWED_MIMES, true)) {
            return true;
        }

        $path = $this->resolvePreviewFilePath($document->file_path);
        if ($path === null || ! function_exists('finfo_open')) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return false;
        }

        $detected = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($detected) && in_array(strtolower($detected), self::PREVIEW_ALLOWED_MIMES, true);
    }
}
