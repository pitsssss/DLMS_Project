<?php

namespace App\Modules\Admin\Controllers;

use App\Enums\ProfileStatus;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Requests\RejectProfileReviewRequest;
use App\Modules\Admin\Resources\ProfileReviewResource;
use App\Modules\Admin\Services\ProfileReviewService;
use Illuminate\Http\Request;

class ProfileReviewController extends Controller
{
    public function index(Request $request, ProfileReviewService $reviews)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_column(ProfileStatus::cases(), 'value'))],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? ProfileStatus::PendingReview->value;
        $paginator = $reviews->paginate(
            $status,
            $validated['search'] ?? null,
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items' => ProfileReviewResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.profile.review_list_retrieved');
    }

    public function show(int $user, ProfileReviewService $reviews)
    {
        $citizen = $reviews->getCitizenForReview($user);

        return $this->successResponse(
            new ProfileReviewResource($citizen),
            'messages.profile.review_details_retrieved'
        );
    }

    public function approve(Request $request, int $user, ProfileReviewService $reviews)
    {
        $citizen = $reviews->approve($request->user(), $user);

        return $this->successResponse(
            new ProfileReviewResource($citizen),
            'messages.profile.approved'
        );
    }

    public function reject(RejectProfileReviewRequest $request, int $user, ProfileReviewService $reviews)
    {
        $citizen = $reviews->reject(
            $request->user(),
            $user,
            $request->validated('rejection_reason')
        );

        return $this->successResponse(
            new ProfileReviewResource($citizen),
            'messages.profile.rejected'
        );
    }
}
