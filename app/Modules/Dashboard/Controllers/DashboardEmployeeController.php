<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\AssignDashboardEmployeeRoleRequest;
use App\Modules\Dashboard\Requests\ResetDashboardEmployeePasswordRequest;
use App\Modules\Dashboard\Requests\StoreDashboardEmployeeRequest;
use App\Modules\Dashboard\Requests\UpdateDashboardEmployeeRequest;
use App\Modules\Dashboard\Resources\DashboardEmployeeResource;
use App\Modules\Dashboard\Services\DashboardEmployeeService;
use Illuminate\Http\Request;

class DashboardEmployeeController extends Controller
{
    public function index(Request $request, DashboardEmployeeService $employees)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $employees->paginate((int) ($validated['per_page'] ?? 20));

        return $this->successResponse([
            'items' => DashboardEmployeeResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.employees_list_retrieved');
    }

    public function store(StoreDashboardEmployeeRequest $request, DashboardEmployeeService $employees)
    {
        $employee = $employees->create($request->validated());

        return $this->successResponse(
            new DashboardEmployeeResource($employee->load('role')),
            'messages.dashboard.employee_created',
            201
        );
    }

    public function show(int $user, DashboardEmployeeService $employees)
    {
        return $this->successResponse(
            new DashboardEmployeeResource($employees->getEmployee($user)->load('role')),
            'messages.dashboard.employee_retrieved'
        );
    }

    public function update(UpdateDashboardEmployeeRequest $request, int $user, DashboardEmployeeService $employees)
    {
        $employee = $employees->update(
            $employees->getEmployee($user),
            $request->validated()
        );

        return $this->successResponse(
            new DashboardEmployeeResource($employee->load('role')),
            'messages.dashboard.employee_updated'
        );
    }

    public function toggleActive(int $user, DashboardEmployeeService $employees)
    {
        $employee = $employees->toggleActive($employees->getEmployee($user));

        return $this->successResponse(
            new DashboardEmployeeResource($employee->load('role')),
            'messages.dashboard.employee_status_updated'
        );
    }

    public function resetPassword(ResetDashboardEmployeePasswordRequest $request, int $user, DashboardEmployeeService $employees)
    {
        $result = $employees->resetPassword(
            $employees->getEmployee($user),
            $request->validated('password') ?? null
        );

        return $this->successResponse($result, 'messages.dashboard.employee_password_reset');
    }

    public function assignRole(AssignDashboardEmployeeRoleRequest $request, int $user, DashboardEmployeeService $employees)
    {
        $employee = $employees->assignRole(
            $employees->getEmployee($user),
            $request->validated('role')
        );

        return $this->successResponse(
            new DashboardEmployeeResource($employee->load('role')),
            'messages.dashboard.role_assigned'
        );
    }
}
