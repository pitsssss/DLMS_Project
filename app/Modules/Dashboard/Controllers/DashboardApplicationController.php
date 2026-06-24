<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Resources\DashboardApplicationResource;
use App\Modules\Dashboard\Services\DashboardApplicationService;
use Illuminate\Http\Request;

class DashboardApplicationController extends Controller
{
    public function index(Request $request, DashboardApplicationService $service)
    {
        $filters = $request->validate([
            'search'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'          => ['sometimes', 'nullable', 'string', 'max:64'],
            'license_type_id' => ['sometimes', 'nullable', 'integer', 'exists:license_types,id'],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $service->paginate($filters, $perPage);

        return $this->successResponse([
            'items' => DashboardApplicationResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.applications_list_retrieved');
    }
}
