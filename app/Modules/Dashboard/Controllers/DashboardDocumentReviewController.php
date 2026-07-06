<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Resources\ApplicationDocumentResource;
use App\Modules\Dashboard\Requests\RejectDashboardDocumentRequest;
use App\Modules\Dashboard\Resources\DashboardDocumentReviewApplicationResource;
use App\Modules\Dashboard\Resources\DashboardDocumentReviewDetailsResource;
use App\Modules\Dashboard\Services\DashboardDocumentReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardDocumentReviewController extends Controller
{
    public function index(Request $request, DashboardDocumentReviewService $reviews)
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:service_types,code'],
            'license_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:license_types,code'],
            'review_status' => ['sometimes', 'nullable', 'string', 'in:awaiting_review,completed,late,reupload_required'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $reviews->paginate($filters, (int) ($filters['per_page'] ?? 20));

        return $this->employeeSuccessResponse([
            'stats' => $reviews->stats(),
            'items' => DashboardDocumentReviewApplicationResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'employee.applications.list_retrieved');
    }

    public function stats(DashboardDocumentReviewService $reviews)
    {
        return $this->employeeSuccessResponse(
            $reviews->stats(),
            'employee.applications.list_retrieved'
        );
    }

    public function show(int $application, DashboardDocumentReviewService $reviews)
    {
        return $this->employeeSuccessResponse(
            new DashboardDocumentReviewDetailsResource($reviews->getApplication($application)),
            'employee.applications.details_retrieved'
        );
    }

    public function approve(Request $request, int $document, DashboardDocumentReviewService $reviews)
    {
        return $this->employeeSuccessResponse(
            new ApplicationDocumentResource($reviews->approve($request->user(), $document)),
            'messages.documents.approved'
        );
    }

    public function reject(
        RejectDashboardDocumentRequest $request,
        int $document,
        DashboardDocumentReviewService $reviews
    ) {
        return $this->employeeSuccessResponse(
            new ApplicationDocumentResource(
                $reviews->reject($request->user(), $document, $request->validated('rejection_reason'))
            ),
            'messages.documents.rejected'
        );
    }

    public function preview(int $document, DashboardDocumentReviewService $reviews): BinaryFileResponse
    {
        $model = $reviews->getPreviewDocument($document);

        return response()->file(
            Storage::disk('local')->path($model->file_path),
            ['Content-Type' => $model->mime_type ?: 'application/octet-stream']
        );
    }
}
