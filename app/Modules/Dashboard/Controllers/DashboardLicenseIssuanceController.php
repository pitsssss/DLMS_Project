<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\ListDashboardLicenseIssuanceRequest;
use App\Modules\Dashboard\Resources\DashboardLicenseIssuanceApplicationResource;
use App\Modules\Dashboard\Services\DashboardLicenseIssuanceService;
use Illuminate\Http\JsonResponse;

class DashboardLicenseIssuanceController extends Controller
{
    public function index(
        ListDashboardLicenseIssuanceRequest $request,
        DashboardLicenseIssuanceService $issuance
    ): JsonResponse {
        $filters = $request->filters();
        $paginator = $issuance->paginate($filters, (int) $filters['per_page']);

        return $this->successResponse([
            'items' => DashboardLicenseIssuanceApplicationResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.licenses.dashboard_issuance_queue_retrieved');
    }

    public function show(int $application, DashboardLicenseIssuanceService $issuance): JsonResponse
    {
        $model = $issuance->getById($application);

        return $this->successResponse(
            (new DashboardLicenseIssuanceApplicationResource($model))->resolve(),
            'messages.licenses.dashboard_issuance_details_retrieved'
        );
    }
}
