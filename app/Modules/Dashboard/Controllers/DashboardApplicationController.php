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
            'search'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'            => ['sometimes', 'nullable', 'string', 'max:64'],
            'license_type_code'  => ['sometimes', 'nullable', 'string', 'max:64', 'exists:license_types,code'],
            'service_type_code'  => ['sometimes', 'nullable', 'string', 'max:64', 'exists:service_types,code'],
            'test_type_code'     => ['sometimes', 'nullable', 'string', 'max:64', 'exists:test_types,code'],
            'per_page'          => ['sometimes', 'integer', 'min:1', 'max:100'],
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
        ], 'Applications list retrieved successfully.');
    }

    public function show(string $application_number, DashboardApplicationService $service)
    {
        // Fetch application by application number (table shows application_number) with only the needed relation and the formatted audit logs
        $details = $service->getDetailsByNumber($application_number);

        return $this->successResponse(
            $details,
            'تم جلب تفاصيل الطلب بنجاح'
        );
    }
}
