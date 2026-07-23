<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\ListDashboardServiceTypesRequest;
use App\Modules\Dashboard\Requests\StoreDashboardServiceTypeRequest;
use App\Modules\Dashboard\Requests\UpdateDashboardServiceTypeRequest;
use App\Modules\Dashboard\Resources\DashboardServiceTypeResource;
use App\Modules\Dashboard\Services\DashboardServiceTypeService;

class DashboardServiceTypeController extends Controller
{
    public function __construct(
        protected DashboardServiceTypeService $serviceTypes,
    ) {}

    public function index(ListDashboardServiceTypesRequest $request)
    {
        $paginator = $this->serviceTypes->paginate($request->filters());

        return $this->successResponse([
            'items' => DashboardServiceTypeResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'statistics' => $this->serviceTypes->statistics(),
        ], 'messages.dashboard.service_types_list_retrieved');
    }

    public function store(StoreDashboardServiceTypeRequest $request)
    {
        $type = $this->serviceTypes->create($request->validated());

        return $this->successResponse(
            new DashboardServiceTypeResource($type),
            'messages.dashboard.service_type_created',
            201
        );
    }

    public function show(int $serviceType)
    {
        return $this->successResponse(
            new DashboardServiceTypeResource($this->serviceTypes->get($serviceType)),
            'messages.dashboard.service_type_retrieved'
        );
    }

    public function update(UpdateDashboardServiceTypeRequest $request, int $serviceType)
    {
        $type = $this->serviceTypes->update(
            $this->serviceTypes->get($serviceType),
            $request->validated()
        );

        return $this->successResponse(
            new DashboardServiceTypeResource($type),
            'messages.dashboard.service_type_updated'
        );
    }

    public function activate(int $serviceType)
    {
        $type = $this->serviceTypes->setActive(
            $this->serviceTypes->get($serviceType),
            true
        );

        return $this->successResponse(
            new DashboardServiceTypeResource($type),
            'messages.dashboard.service_type_activated'
        );
    }

    public function deactivate(int $serviceType)
    {
        $type = $this->serviceTypes->setActive(
            $this->serviceTypes->get($serviceType),
            false
        );

        return $this->successResponse(
            new DashboardServiceTypeResource($type),
            'messages.dashboard.service_type_deactivated'
        );
    }
}
