<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\DashboardOverviewRequest;
use App\Modules\Dashboard\Services\DashboardOverviewService;

class DashboardOverviewController extends Controller
{
    public function __construct(
        protected DashboardOverviewService $overview,
    ) {}

    public function __invoke(DashboardOverviewRequest $request)
    {
        return $this->successResponse(
            $this->overview->build($request->user(), $request->filters()),
            'messages.dashboard.overview_retrieved'
        );
    }
}
