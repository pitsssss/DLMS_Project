<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\DashboardDueFeesIndexRequest;
use App\Modules\Dashboard\Requests\DashboardPaymentIndexRequest;
use App\Modules\Dashboard\Requests\DashboardPaymentStatsRequest;
use App\Modules\Dashboard\Resources\DashboardDueFeeResource;
use App\Modules\Dashboard\Resources\DashboardPaymentAttemptResource;
use App\Modules\Dashboard\Resources\DashboardPaymentAuditLogResource;
use App\Modules\Dashboard\Resources\DashboardPaymentDetailsResource;
use App\Modules\Dashboard\Resources\DashboardPaymentResource;
use App\Modules\Dashboard\Services\DashboardPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardPaymentController extends Controller
{
    public function index(DashboardPaymentIndexRequest $request, DashboardPaymentService $payments): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $payments->paginate($filters, (int) ($filters['per_page'] ?? 20));

        return $this->successResponse([
            'items' => DashboardPaymentResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.payments.dashboard_list_retrieved');
    }

    public function stats(DashboardPaymentStatsRequest $request, DashboardPaymentService $payments): JsonResponse
    {
        return $this->successResponse(
            $payments->stats($request->validated()),
            'messages.payments.dashboard_stats_retrieved'
        );
    }

    public function options(DashboardPaymentService $payments): JsonResponse
    {
        return $this->successResponse(
            $payments->options(),
            'messages.payments.dashboard_options_retrieved'
        );
    }

    public function dueFees(DashboardDueFeesIndexRequest $request, DashboardPaymentService $payments): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $payments->paginateDueFees($filters, (int) ($filters['per_page'] ?? 20));

        return $this->successResponse([
            'items' => DashboardDueFeeResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.payments.dashboard_due_fees_retrieved');
    }

    public function show(int $payment, DashboardPaymentService $payments): JsonResponse
    {
        $model = $payments->getPayment($payment);

        return $this->successResponse(
            new DashboardPaymentDetailsResource($model),
            'messages.payments.dashboard_details_retrieved'
        );
    }

    public function attempts(Request $request, int $payment, DashboardPaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $model = $payments->getPayment($payment);
        $paginator = $payments->paginateAttempts($model, (int) ($validated['per_page'] ?? 20));

        return $this->successResponse([
            'items' => DashboardPaymentAttemptResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.payments.dashboard_attempts_retrieved');
    }

    public function auditLogs(Request $request, int $payment, DashboardPaymentService $payments): JsonResponse
    {
        if (! $request->user()?->hasPermission('view_audit_logs')) {
            return $this->errorResponse('messages.dashboard.forbidden', [], 403);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $model = $payments->getPayment($payment);
        $paginator = $payments->paginateAuditLogs($model, (int) ($validated['per_page'] ?? 20));

        return $this->successResponse([
            'items' => DashboardPaymentAuditLogResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.payments.dashboard_audit_logs_retrieved');
    }

    public function verify(Request $request, int $payment, DashboardPaymentService $payments): JsonResponse
    {
        $result = $payments->verify($request->user(), $payment);
        $details = $payments->getPayment($result['payment']->id);

        return $this->successResponse(
            new DashboardPaymentDetailsResource($details),
            'messages.payments.dashboard_verified'
        );
    }
}
