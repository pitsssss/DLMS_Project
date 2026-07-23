<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\ListDashboardLicenseTypesRequest;
use App\Modules\Dashboard\Requests\StoreDashboardLicenseTypeRequest;
use App\Modules\Dashboard\Requests\UpdateDashboardLicenseTypeRequest;
use App\Modules\Dashboard\Resources\DashboardLicenseTypeResource;
use App\Modules\Dashboard\Services\DashboardLicenseTypeService;

class DashboardLicenseTypeController extends Controller
{
    public function __construct(
        protected DashboardLicenseTypeService $licenseTypes,
    ) {}

    public function index(ListDashboardLicenseTypesRequest $request)
    {
        $paginator = $this->licenseTypes->paginate($request->filters());

        return $this->successResponse([
            'items' => DashboardLicenseTypeResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'statistics' => $this->licenseTypes->statistics(),
        ], 'messages.dashboard.license_types_list_retrieved');
    }

    public function store(StoreDashboardLicenseTypeRequest $request)
    {
        $type = $this->licenseTypes->create($request->validated());

        return $this->successResponse(
            new DashboardLicenseTypeResource($type),
            'messages.dashboard.license_type_created',
            201
        );
    }

    public function show(int $licenseType)
    {
        return $this->successResponse(
            new DashboardLicenseTypeResource($this->licenseTypes->get($licenseType)),
            'messages.dashboard.license_type_retrieved'
        );
    }

    public function update(UpdateDashboardLicenseTypeRequest $request, int $licenseType)
    {
        $type = $this->licenseTypes->update(
            $this->licenseTypes->get($licenseType),
            $request->validated()
        );

        return $this->successResponse(
            new DashboardLicenseTypeResource($type),
            'messages.dashboard.license_type_updated'
        );
    }

    public function activate(int $licenseType)
    {
        $type = $this->licenseTypes->setActive(
            $this->licenseTypes->get($licenseType),
            true
        );

        return $this->successResponse(
            new DashboardLicenseTypeResource($type),
            'messages.dashboard.license_type_activated'
        );
    }

    public function deactivate(int $licenseType)
    {
        $type = $this->licenseTypes->setActive(
            $this->licenseTypes->get($licenseType),
            false
        );

        return $this->successResponse(
            new DashboardLicenseTypeResource($type),
            'messages.dashboard.license_type_deactivated'
        );
    }
}
