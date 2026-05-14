<?php

namespace App\Modules\Applications\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\ApiException;
use App\Models\ApplicationDocument;
use App\Models\LicenseApplication;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Modules\Applications\Repositories\ApplicationRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ApplicationDocumentService
{
    public function __construct(
        private readonly ApplicationRepository $applications
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function requiredChecklist(User $citizen, int $applicationId): array
    {
        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('Application not found.', 404);
        }

        return $this->requiredDocumentsForApplication($application)
            ->map(function (RequiredDocument $rd) use ($application) {
                $latest = $this->latestDocument($application->id, $rd->id);

                return [
                    'id' => $rd->id,
                    'name' => $rd->name,
                    'code' => $rd->code,
                    'is_required' => $rd->is_required,
                    'allowed_extensions' => $rd->allowed_extensions,
                    'max_size_kb' => $rd->max_size_kb,
                    'latest_document' => $latest
                        ? (new ApplicationDocumentResource($latest->loadMissing('requiredDocument')))->resolve()
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ApplicationDocument>
     */
    public function listDocuments(User $citizen, int $applicationId)
    {
        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('Application not found.', 404);
        }

        return ApplicationDocument::query()
            ->where('application_id', $application->id)
            ->with('requiredDocument')
            ->orderByDesc('id')
            ->get();
    }

    public function upload(User $citizen, int $applicationId, int $requiredDocumentId, UploadedFile $file): ApplicationDocument
    {
        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('Application not found.', 404);
        }

        $this->assertApplicationAllowsDocumentEdits($application);

        $required = RequiredDocument::query()->whereKey($requiredDocumentId)->where('is_active', true)->first();

        if ($required === null) {
            throw new ApiException('Invalid required document.', 422);
        }

        $this->assertRequiredDocumentAppliesToApplication($application, $required);
        $this->validateFileAgainstRules($file, $required);

        return DB::transaction(function () use ($application, $required, $file) {
            ApplicationDocument::query()
                ->where('application_id', $application->id)
                ->where('required_document_id', $required->id)
                ->delete();

            $extension = strtolower((string) ($file->getClientOriginalExtension() ?: 'bin'));
            $filename = Str::uuid()->toString().'.'.$extension;
            $directory = 'application_documents/'.$application->id;

            $storedPath = Storage::disk('local')->putFileAs($directory, $file, $filename);

            if ($storedPath === false) {
                throw new ApiException('Could not store the uploaded file.', 500);
            }

            return ApplicationDocument::query()->create([
                'application_id' => $application->id,
                'required_document_id' => $required->id,
                'file_path' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'status' => DocumentStatus::PendingReview,
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->load('requiredDocument');
        });
    }

    public function submitForReview(User $citizen, int $applicationId): LicenseApplication
    {
        $application = $this->applications->findOwnedByCitizen($citizen, $applicationId);

        if ($application === null) {
            throw new ApiException('Application not found.', 404);
        }

        if (! in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::DocumentsRejected], true)) {
            throw new ApiException('This application cannot be submitted for document review in its current state.', 422);
        }

        $required = $this->requiredDocumentsForApplication($application)->where('is_required', true);

        foreach ($required as $rd) {
            $latest = $this->latestDocument($application->id, $rd->id);

            if ($latest === null) {
                throw new ApiException('All required documents must be uploaded before submission.', 422);
            }

            if ($latest->status === DocumentStatus::Rejected) {
                throw new ApiException('Replace rejected documents before submission.', 422);
            }
        }

        return $this->applications->transitionStatus(
            $application,
            ApplicationStatus::DocumentsUnderReview,
            $citizen,
            'Citizen submitted documents for review.'
        );
    }

    private function assertApplicationAllowsDocumentEdits(LicenseApplication $application): void
    {
        if (! in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::DocumentsRejected], true)) {
            throw new ApiException('Documents cannot be modified for this application in its current state.', 422);
        }
    }

    private function assertRequiredDocumentAppliesToApplication(LicenseApplication $application, RequiredDocument $required): void
    {
        $licenseOk = $required->license_type_id === null || (int) $required->license_type_id === (int) $application->license_type_id;
        $serviceOk = $required->service_type_id === null || (int) $required->service_type_id === (int) $application->service_type_id;

        if (! $licenseOk || ! $serviceOk) {
            throw new ApiException('This document type does not apply to this application.', 422);
        }
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

    private function latestDocument(int $applicationId, int $requiredDocumentId): ?ApplicationDocument
    {
        return ApplicationDocument::query()
            ->where('application_id', $applicationId)
            ->where('required_document_id', $requiredDocumentId)
            ->orderByDesc('id')
            ->first();
    }

    private function validateFileAgainstRules(UploadedFile $file, RequiredDocument $required): void
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: ''));

        $allowed = $required->allowed_extensions;
        if ($allowed === null || $allowed === []) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        }

        $allowed = array_map('strtolower', $allowed);

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw new ApiException('Invalid file type for this document.', 422);
        }

        $maxKb = $required->max_size_kb ?? 4096;
        $maxBytes = $maxKb * 1024;

        if ($file->getSize() > $maxBytes) {
            throw new ApiException("File exceeds maximum size of {$maxKb} KB.", 422);
        }
    }
}
