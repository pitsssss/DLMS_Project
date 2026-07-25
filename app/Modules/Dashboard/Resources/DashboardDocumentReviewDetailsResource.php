<?php

namespace App\Modules\Dashboard\Resources;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentRejectionReason;
use App\Enums\DocumentStatus;
use App\Support\EmployeeMessageTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class DashboardDocumentReviewDetailsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = new DashboardDocumentReviewApplicationResource($this->resource);

        return [
            'summary' => $summary->resolve($request),
            'header' => [
                'application_number' => $this->application_number,
                'citizen_name' => $this->citizen?->name,
                'service_type' => $this->serviceType
                    ? EmployeeMessageTranslator::get('employee.services.'.$this->serviceType->code)
                    : null,
                'license_type' => $this->licenseType
                    ? EmployeeMessageTranslator::get('employee.license_types.'.$this->licenseType->code)
                    : null,
                'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
                'application_status' => [
                    'value' => $this->status instanceof ApplicationStatus
                        ? $this->status->value
                        : (string) $this->status,
                    'label' => $this->applicationStatusLabel(),
                ],
            ],
            'documents' => $this->documents($request),
            'rejection_reasons' => DocumentRejectionReason::options(),
        ];
    }

    private function documents(Request $request): array
    {
        $applicationUnderReview = $this->status === ApplicationStatus::DocumentsUnderReview;

        return $this->checklist()
            ->map(function (array $item) use ($applicationUnderReview): array {
                $required = $item['required_document'];
                $document = $item['latest_document'];
                $canReview = $applicationUnderReview
                    && $document !== null
                    && $document->status === DocumentStatus::PendingReview;

                return [
                    'required_document' => [
                        'id' => $required->id,
                        'name' => $required->name,
                        'code' => $required->code,
                        'is_required' => (bool) $required->is_required,
                        'allowed_extensions' => $required->allowed_extensions,
                        'max_size_kb' => $required->max_size_kb,
                    ],
                    'status' => $this->documentState($document),
                    'latest_document' => $document ? [
                        'id' => $document->id,
                        'status' => $document->status->value,
                        'status_label' => $this->documentStatusLabel($document->status),
                        'original_name' => $document->original_name,
                        'mime_type' => $document->mime_type,
                        'size' => $document->size,
                        'size_label' => $this->sizeLabel((int) $document->size),
                        'uploaded_at' => $document->created_at?->format('Y-m-d H:i:s'),
                        'reviewed_at' => $document->reviewed_at?->format('Y-m-d H:i:s'),
                        'rejection' => $this->rejectionPayload($document),
                        // Legacy compatibility display text — prefer rejection.* for new clients
                        'rejection_reason' => $document->rejection_reason,
                        'preview_url' => url('/api/dashboard/document-reviews/documents/'.$document->id.'/preview'),
                    ] : null,
                    'actions' => [
                        'can_approve' => $canReview,
                        'can_reject' => $canReview,
                        'document_id' => $document?->id,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function checklist(): Collection
    {
        if ($this->relationLoaded('dashboardDocumentReviewChecklist')) {
            return $this->getRelation('dashboardDocumentReviewChecklist');
        }

        return collect();
    }

    private function documentState($document): array
    {
        if ($document === null) {
            return ['value' => 'missing', 'label' => 'غير مرفوع'];
        }

        return [
            'value' => $document->status->value,
            'label' => $this->documentStatusLabel($document->status),
        ];
    }

    private function rejectionPayload($document): ?array
    {
        if ($document->status !== DocumentStatus::Rejected) {
            return null;
        }

        $code = DocumentRejectionReason::tryFrom((string) $document->rejection_reason_code);

        return [
            'code' => $code?->value ?? $document->rejection_reason_code,
            'label' => $code?->label() ?? ($document->rejection_reason ?: null),
            'details' => $document->rejection_details,
        ];
    }

    private function documentStatusLabel(DocumentStatus $status): string
    {
        return match ($status) {
            DocumentStatus::PendingReview => 'بانتظار المراجعة',
            DocumentStatus::Approved => 'مقبول',
            DocumentStatus::Rejected => 'مرفوض',
        };
    }

    private function applicationStatusLabel(): string
    {
        $status = $this->status instanceof ApplicationStatus
            ? $this->status
            : ApplicationStatus::tryFrom((string) $this->status);

        return match ($status) {
            ApplicationStatus::DocumentsUnderReview => 'الوثائق قيد المراجعة',
            ApplicationStatus::DocumentsRejected => 'وثائق مرفوضة',
            ApplicationStatus::PaymentPending => 'بانتظار الدفع',
            default => $status?->value ?? (string) $this->status,
        };
    }

    private function sizeLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1024, 1).' KB';
    }
}
