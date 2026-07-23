<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\AssignDashboardEmployeeRoleRequest;
use App\Modules\Dashboard\Requests\ListDashboardEmployeesRequest;
use App\Modules\Dashboard\Requests\ResetDashboardEmployeePasswordRequest;
use App\Modules\Dashboard\Requests\StoreDashboardEmployeeRequest;
use App\Modules\Dashboard\Requests\UpdateDashboardEmployeeRequest;
use App\Modules\Dashboard\Resources\DashboardEmployeeResource;
use App\Modules\Dashboard\Services\DashboardEmployeeService;
use Illuminate\Http\Request;

class DashboardEmployeeController extends Controller
{
    public function index(ListDashboardEmployeesRequest $request, DashboardEmployeeService $employees)
    {
        $filters = $request->filters();
        $paginator = $employees->paginate($filters);

        return $this->successResponse([
            'items' => DashboardEmployeeResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'statistics' => $employees->statistics(),
            'role_options' => $employees->roleOptions(),
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

    public function activate(Request $request, int $user, DashboardEmployeeService $employees)
    {
        $employee = $employees->setActive(
            $employees->getEmployee($user),
            true,
            $request->user()
        );

        return $this->successResponse(
            new DashboardEmployeeResource($employee),
            'messages.dashboard.employee_activated'
        );
    }

    public function deactivate(Request $request, int $user, DashboardEmployeeService $employees)
    {
        $employee = $employees->setActive(
            $employees->getEmployee($user),
            false,
            $request->user()
        );

        return $this->successResponse(
            new DashboardEmployeeResource($employee),
            'messages.dashboard.employee_deactivated'
        );
    }

    /**
     * Legacy toggle / explicit set via body { "is_active": true|false }.
     * Prefer activate/deactivate endpoints for new clients.
     */
    public function toggleActive(Request $request, int $user, DashboardEmployeeService $employees)
    {
        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $desired = array_key_exists('is_active', $validated)
            ? filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $employee = $employees->setActive(
            $employees->getEmployee($user),
            $desired,
            $request->user()
        );

        $message = $employee->is_active
            ? 'messages.dashboard.employee_activated'
            : 'messages.dashboard.employee_deactivated';

        return $this->successResponse(
            new DashboardEmployeeResource($employee->load('role')),
            $message
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
