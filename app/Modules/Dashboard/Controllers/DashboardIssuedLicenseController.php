<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Requests\BlockDashboardLicenseRequest;
use App\Modules\Dashboard\Requests\ListDashboardLicensesRequest;
use App\Modules\Dashboard\Services\DashboardIssuedLicenseService;
use App\Modules\Licenses\Services\LicensePrintService;
use App\Modules\Licenses\Services\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardIssuedLicenseController extends Controller
{
    public function index(ListDashboardLicensesRequest $request, DashboardIssuedLicenseService $licenses): JsonResponse
    {
        $filters = $request->filters();
        $actor = $request->user();
        $paginator = $licenses->paginate($filters, $actor);

        $items = collect($paginator->items())
            ->map(fn ($license) => $licenses->transformListItem($license, $actor))
            ->values()
            ->all();

        return $this->successResponse([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.licenses.dashboard_list_retrieved');
    }

    public function stats(ListDashboardLicensesRequest $request, DashboardIssuedLicenseService $licenses): JsonResponse
    {
        $filters = $request->filters();
        unset($filters['page'], $filters['per_page']);

        return $this->successResponse(
            $licenses->stats($filters, $request->user()),
            'messages.licenses.dashboard_stats_retrieved'
        );
    }

    public function options(Request $request, DashboardIssuedLicenseService $licenses): JsonResponse
    {
        return $this->successResponse(
            $licenses->options($request->user()),
            'messages.licenses.dashboard_options_retrieved'
        );
    }

    public function show(Request $request, int $license, DashboardIssuedLicenseService $licenses): JsonResponse
    {
        $model = $licenses->getLicense($license);

        return $this->successResponse(
            $licenses->details($model, $request->user()),
            'messages.licenses.dashboard_details_retrieved'
        );
    }

    public function history(Request $request, int $license, DashboardIssuedLicenseService $licenses): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $model = $licenses->getLicense($license);
        $paginator = $licenses->paginateHistory($model, (int) ($validated['per_page'] ?? 20));

        return $this->successResponse([
            'items' => collect($paginator->items())
                ->map(fn ($row) => $licenses->transformHistoryItem($row))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.licenses.dashboard_history_retrieved');
    }

    public function auditLogs(Request $request, int $license, DashboardIssuedLicenseService $licenses): JsonResponse
    {
        if (! $request->user()?->hasPermission('view_audit_logs')) {
            return $this->errorResponse('messages.dashboard.forbidden', [], 403);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $model = $licenses->getLicense($license);
        $paginator = $licenses->paginateAuditLogs($model, (int) ($validated['per_page'] ?? 20));

        return $this->successResponse([
            'items' => collect($paginator->items())
                ->map(fn ($row) => $licenses->transformAuditItem($row))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.licenses.dashboard_audit_logs_retrieved');
    }

    public function print(Request $request, int $license, DashboardIssuedLicenseService $issued, LicensePrintService $printer): Response
    {
        $model = $issued->getLicense($license);
        $result = $printer->printPdf($request->user(), $model);

        return response($result['binary'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function block(
        BlockDashboardLicenseRequest $request,
        int $license,
        LicenseService $licenses,
        DashboardIssuedLicenseService $issued
    ): JsonResponse {
        $licenses->block($request->user(), $license, $request->validated('reason'));
        $model = $issued->getLicense($license);

        return $this->successResponse(
            $issued->details($model, $request->user()),
            'messages.licenses.blocked'
        );
    }

    public function unblock(
        Request $request,
        int $license,
        LicenseService $licenses,
        DashboardIssuedLicenseService $issued
    ): JsonResponse {
        $licenses->unblock($request->user(), $license);
        $model = $issued->getLicense($license);

        return $this->successResponse(
            $issued->details($model, $request->user()),
            'messages.licenses.unblocked'
        );
    }
}
