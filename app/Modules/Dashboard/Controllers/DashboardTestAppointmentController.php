<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\ListDashboardTestAppointmentsRequest;
use App\Modules\Dashboard\Resources\DashboardTestAppointmentResource;
use App\Modules\Dashboard\Services\DashboardTestAppointmentService;
use Illuminate\Http\JsonResponse;

class DashboardTestAppointmentController extends Controller
{
    public function index(
        ListDashboardTestAppointmentsRequest $request,
        DashboardTestAppointmentService $appointments
    ): JsonResponse {
        $filters = $request->filters();
        $paginator = $appointments->paginate($filters, (int) $filters['per_page']);

        return $this->successResponse([
            'items' => DashboardTestAppointmentResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.tests.dashboard_list_retrieved');
    }
}
