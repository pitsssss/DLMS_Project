<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Resources\ApplicationDocumentResource;
use App\Modules\Dashboard\Requests\DashboardDocumentReviewIndexRequest;
use App\Modules\Dashboard\Requests\RejectDashboardDocumentRequest;
use App\Modules\Dashboard\Resources\DashboardDocumentReviewApplicationResource;
use App\Modules\Dashboard\Resources\DashboardDocumentReviewDetailsResource;
use App\Modules\Dashboard\Services\DashboardDocumentReviewService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardDocumentReviewController extends Controller
{
    public function index(DashboardDocumentReviewIndexRequest $request, DashboardDocumentReviewService $reviews)
    {
        $filters = $request->validated();
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
                $reviews->reject(
                    $request->user(),
                    $document,
                    $request->rejectionReason(),
                    $request->rejectionDetails()
                )
            ),
            'messages.documents.rejected'
        );
    }

    public function preview(int $document, DashboardDocumentReviewService $reviews): BinaryFileResponse
    {
        $model = $reviews->getPreviewDocument($document);
        $path = $reviews->getPreviewFilePath($model);
        $filename = $reviews->sanitizedPreviewFilename($model);
        $contentType = $reviews->resolvePreviewContentType($model);

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
