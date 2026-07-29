<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Fee;
use App\Modules\Dashboard\Requests\DeactivateDashboardFeeRequest;
use App\Modules\Dashboard\Requests\ListDashboardFeesRequest;
use App\Modules\Dashboard\Requests\StoreDashboardFeeRequest;
use App\Modules\Dashboard\Requests\UpdateDashboardFeeRequest;
use App\Modules\Dashboard\Resources\DashboardFeeResource;
use App\Modules\Dashboard\Services\DashboardFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardFeeController extends Controller
{
    public function __construct(
        private readonly DashboardFeeService $fees,
    ) {}

    public function index(ListDashboardFeesRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $paginator = $this->fees->paginate($filters, $request->user());

        return $this->successResponse([
            'items' => DashboardFeeResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'messages.fees.list_retrieved');
    }

    public function stats(ListDashboardFeesRequest $request): JsonResponse
    {
        $filters = $request->filters();
        unset($filters['page'], $filters['per_page']);

        return $this->successResponse(
            $this->fees->stats($filters),
            'messages.fees.stats_retrieved'
        );
    }

    public function options(): JsonResponse
    {
        return $this->successResponse(
            $this->fees->options(),
            'messages.fees.options_retrieved'
        );
    }

    public function show(int $fee): JsonResponse
    {
        $model = $this->fees->get($fee);

        return $this->successResponse(
            DashboardFeeResource::detail($model)->resolve(),
            'messages.fees.details_retrieved'
        );
    }

    public function store(StoreDashboardFeeRequest $request): JsonResponse
    {
        $model = $this->fees->create($request->validated(), $request->user(), $request);

        return $this->successResponse(
            DashboardFeeResource::detail($model)->resolve(),
            'messages.fees.created',
            201
        );
    }

    public function update(UpdateDashboardFeeRequest $request, int $fee): JsonResponse
    {
        $model = $this->fees->update(
            $this->fees->get($fee),
            $request->validated(),
            $request->user(),
            $request
        );

        return $this->successResponse(
            DashboardFeeResource::detail($model)->resolve(),
            'messages.fees.updated'
        );
    }

    public function activate(Request $request, int $fee): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $model = $this->fees->activate(
            $this->fees->get($fee),
            $request->user(),
            $request,
            $validated['reason'] ?? null
        );

        return $this->successResponse(
            DashboardFeeResource::detail($model)->resolve(),
            'messages.fees.activated'
        );
    }

    public function deactivate(DeactivateDashboardFeeRequest $request, int $fee): JsonResponse
    {
        $model = $this->fees->deactivate(
            $this->fees->get($fee),
            $request->user(),
            $request,
            $request->validated('reason')
        );

        return $this->successResponse(
            DashboardFeeResource::detail($model)->resolve(),
            'messages.fees.deactivated'
        );
    }

    public function auditLogs(Request $request, int $fee): JsonResponse
    {
        if (! $request->user()?->hasPermission('view_audit_logs')) {
            return $this->errorResponse('messages.dashboard.forbidden', [], 403);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $model = Fee::query()->findOrFail($fee);
        $paginator = $this->fees->paginateAuditLogs($model, (int) ($validated['per_page'] ?? 20));

        return $this->successResponse([
            'items' => collect($paginator->items())
                ->map(fn ($row) => $this->fees->transformAuditItem($row))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.fees.audit_logs_retrieved');
    }
}
