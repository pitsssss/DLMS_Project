<?php

namespace App\Modules\Dashboard\Resources;

use App\Enums\ApplicationStatus;
use App\Support\EmployeeMessageTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class DashboardDocumentReviewApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $checklist = $this->checklist();
        $required = $checklist->count();
        $uploaded = $checklist->where('is_uploaded', true)->count();
        $approved = $checklist->where('is_approved', true)->count();
        $pending = $checklist->where('is_pending_review', true)->count();
        $rejected = $checklist->where('is_rejected', true)->count();

        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'review_status' => $this->reviewStatus(),
            'is_late' => $this->isLate(),
            'citizen' => $this->relationLoaded('citizen') && $this->citizen ? [
                'id' => $this->citizen->id,
                'name' => $this->citizen->name,
            ] : null,
            'service_type' => $this->relationLoaded('serviceType') && $this->serviceType ? [
                'code' => $this->serviceType->code,
                'name' => EmployeeMessageTranslator::get('employee.services.'.$this->serviceType->code),
            ] : null,
            'license_type' => $this->relationLoaded('licenseType') && $this->licenseType ? [
                'code' => $this->licenseType->code,
                'name' => EmployeeMessageTranslator::get('employee.license_types.'.$this->licenseType->code),
            ] : null,
            'documents_progress' => [
                'required' => $required,
                'uploaded' => $uploaded,
                'approved' => $approved,
                'pending_review' => $pending,
                'rejected' => $rejected,
                'missing' => max(0, $required - $uploaded),
                'label' => $uploaded.'/'.$required.' تم الرفع',
            ],
        ];
    }

    private function checklist(): Collection
    {
        if ($this->relationLoaded('dashboardDocumentReviewChecklist')) {
            return $this->getRelation('dashboardDocumentReviewChecklist');
        }

        return collect();
    }

    private function reviewStatus(): array
    {
        $status = $this->status instanceof ApplicationStatus ? $this->status : ApplicationStatus::tryFrom((string) $this->status);

        if ($status === ApplicationStatus::DocumentsRejected) {
            return ['value' => 'reupload_required', 'label' => 'وثائق مطلوبة'];
        }

        if ($status === ApplicationStatus::DocumentsUnderReview && $this->isLate()) {
            return ['value' => 'late', 'label' => 'متأخر'];
        }

        if ($status === ApplicationStatus::DocumentsUnderReview) {
            return ['value' => 'awaiting_review', 'label' => 'بانتظار المراجعة'];
        }

        return ['value' => 'completed', 'label' => 'مكتملة الوثائق'];
    }

    private function isLate(): bool
    {
        $date = $this->submitted_at ?? $this->created_at;

        return $this->status === ApplicationStatus::DocumentsUnderReview
            && $date !== null
            && $date->lte(now()->subDays(3));
    }
}
