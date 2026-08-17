<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Services\ApplicationUnblockService;
use App\Modules\Dashboard\Requests\RejectDashboardApplicationRequest;
use App\Modules\Dashboard\Resources\DashboardApplicationResource;
use App\Modules\Dashboard\Services\DashboardApplicationService;
use App\Modules\Licenses\Resources\LicenseResource;
use Illuminate\Http\Request;
use App\Modules\Dashboard\Resources\DashboardApplicationDetailsResource;
class DashboardApplicationController extends Controller
{
    public function index(Request $request, DashboardApplicationService $service)
    {
        $filters = $request->validate([
            'search'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'            => ['sometimes', 'nullable', 'string', 'max:64'],
            'license_type_code'  => ['sometimes', 'nullable', 'string', 'max:64', 'exists:license_types,code'],
            'service_type_code'  => ['sometimes', 'nullable', 'string', 'max:64', 'exists:service_types,code'],
            'test_type_code'     => ['sometimes', 'nullable', 'string', 'max:64', 'exists:test_types,code'],
            'per_page'          => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $service->paginate($filters, $perPage);

        return $this->employeeSuccessResponse([
            'items' => DashboardApplicationResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 'employee.applications.list_retrieved');
    }

    public function show(string $application_number, DashboardApplicationService $service)
    {
        $application = $service->getDetailsByNumber($application_number);

        return $this->employeeSuccessResponse(
            (new DashboardApplicationDetailsResource($application))->resolve(),
            'employee.applications.details_retrieved'
        );
    }

    public function unblockLicense(
        Request $request,
        int $application,
        ApplicationUnblockService $unblockService,
    ) {
        $result = $unblockService->unblockFromApplication($request->user(), $application);

        return $this->employeeSuccessResponse([
            'application' => (new DashboardApplicationResource($result['application']))->resolve(),
            'license' => (new LicenseResource($result['license']))->resolve(),
        ], 'messages.applications.unblock_completed');
    }

    public function reject(
        RejectDashboardApplicationRequest $request,
        int $application,
        ApplicationUnblockService $unblockService,
    ) {
        $model = $unblockService->rejectApprovedUnblockApplication(
            $request->user(),
            $application,
            $request->validated('reason')
        );

        return $this->employeeSuccessResponse(
            (new DashboardApplicationResource($model))->resolve(),
            'messages.applications.rejected'
        );
    }
}
