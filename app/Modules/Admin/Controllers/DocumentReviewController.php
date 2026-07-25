<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Requests\RejectApplicationDocumentRequest;
use App\Modules\Admin\Services\DocumentReviewService;
use App\Modules\Applications\Resources\ApplicationDocumentResource;
use Illuminate\Http\Request;

class DocumentReviewController extends Controller
{
    public function pending(Request $request, DocumentReviewService $reviews)
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min(50, $perPage));

        $paginator = $reviews->paginatePending($request->user(), $perPage);

        $items = collect($paginator->items())
            ->map(fn ($doc) => (new ApplicationDocumentResource($doc))->resolve())
            ->values()
            ->all();

        return $this->successResponse([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.documents.pending_list');
    }

    public function approve(Request $request, int $document, DocumentReviewService $reviews)
    {
        $model = $reviews->approve($request->user(), $document);

        return $this->successResponse(
            new ApplicationDocumentResource($model),
            'messages.documents.approved'
        );
    }

    public function reject(
        RejectApplicationDocumentRequest $request,
        int $document,
        DocumentReviewService $reviews
    ) {
        $model = $reviews->rejectFromLegacyReason(
            $request->user(),
            $document,
            (string) $request->validated('rejection_reason')
        );

        return $this->successResponse(
            new ApplicationDocumentResource($model),
            'messages.documents.rejected'
        );
    }
}
