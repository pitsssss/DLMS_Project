<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Dashboard\Requests\DeactivateDashboardCitizenRequest;
use App\Modules\Dashboard\Requests\DashboardCitizenIndexRequest;
use App\Modules\Dashboard\Requests\UpdateDashboardCitizenRequest;
use App\Modules\Dashboard\Resources\DashboardApplicationResource;
use App\Modules\Dashboard\Resources\DashboardCitizenAuditLogResource;
use App\Modules\Dashboard\Resources\DashboardCitizenDetailsResource;
use App\Modules\Dashboard\Resources\DashboardCitizenResource;
use App\Modules\Dashboard\Services\DashboardCitizenService;
use App\Modules\Fines\Resources\FineResource;
use App\Modules\Licenses\Resources\LicenseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardCitizenController extends Controller
{
    public function index(DashboardCitizenIndexRequest $request, DashboardCitizenService $citizens): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 20);

        $paginator = $citizens->paginate($filters, $perPage);

        return $this->successResponse([
            'items'      => DashboardCitizenResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.citizens_list_retrieved');
    }

    public function stats(DashboardCitizenService $citizens): JsonResponse
    {
        return $this->successResponse(
            $citizens->stats(),
            'messages.dashboard.citizen_stats_retrieved'
        );
    }

    /** @deprecated Kept for backward compatibility. Use GET /citizens?search= instead. */
    public function search(Request $request, DashboardCitizenService $citizens): JsonResponse
    {
        $term    = trim((string) $request->input('search_term', ''));
        $records = $citizens->searchCitizens($term);

        return $this->successResponse(
            DashboardCitizenResource::collection($records)->resolve(),
            'messages.dashboard.citizens_list_retrieved'
        );
    }

    public function profileStatuses(DashboardCitizenService $citizens): JsonResponse
    {
        return $this->successResponse(
            $citizens->profileStatuses(),
            'messages.dashboard.profile_statuses_retrieved'
        );
    }

    public function show(int $citizen, DashboardCitizenService $citizens): JsonResponse
    {
        $citizenModel = $citizens->getCitizenWithDetails($citizen);

        return $this->successResponse(
            new DashboardCitizenDetailsResource($citizenModel),
            'messages.dashboard.citizen_retrieved'
        );
    }

    public function update(UpdateDashboardCitizenRequest $request, int $citizen, DashboardCitizenService $citizens): JsonResponse
    {
        $citizenModel = $citizens->getCitizen($citizen);

        $updated = $citizens->update(
            $request->user(),
            $citizenModel,
            $request->validated()
        );

        return $this->successResponse(
            new DashboardCitizenDetailsResource($citizens->getCitizenWithDetails($updated->id)),
            'messages.dashboard.citizen_updated'
        );
    }

    public function activate(int $citizen, DashboardCitizenService $citizens, Request $request): JsonResponse
    {
        $citizenModel = $citizens->getCitizen($citizen);
        $updated = $citizens->activate($request->user(), $citizenModel->id);

        return $this->successResponse(
            new DashboardCitizenDetailsResource($citizens->getCitizenWithDetails($updated->id)),
            'messages.dashboard.citizen_activated'
        );
    }

    public function deactivate(DeactivateDashboardCitizenRequest $request, int $citizen, DashboardCitizenService $citizens): JsonResponse
    {
        $citizenModel = $citizens->getCitizen($citizen);
        $updated = $citizens->deactivate($request->user(), $citizenModel->id, $request->validated('reason'));

        return $this->successResponse(
            new DashboardCitizenDetailsResource($citizens->getCitizenWithDetails($updated->id)),
            'messages.dashboard.citizen_deactivated'
        );
    }

    public function applications(Request $request, int $citizen, DashboardCitizenService $citizens): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $citizens->citizenApplications(
            $citizens->getCitizen($citizen),
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items'      => DashboardApplicationResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.citizen_applications_retrieved');
    }

    public function licenses(Request $request, int $citizen, DashboardCitizenService $citizens): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $citizens->citizenLicenses(
            $citizens->getCitizen($citizen),
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items'      => LicenseResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.citizen_licenses_retrieved');
    }

    public function fines(Request $request, int $citizen, DashboardCitizenService $citizens): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $citizens->citizenFines(
            $citizens->getCitizen($citizen),
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items'      => FineResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.citizen_fines_retrieved');
    }

    public function auditLogs(Request $request, int $citizen, DashboardCitizenService $citizens): JsonResponse
    {
        if (! $request->user()?->hasPermission('view_audit_logs')) {
            return $this->errorResponse('messages.dashboard.forbidden', 403);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $citizenModel = $citizens->getCitizen($citizen);

        $paginator = $citizens->citizenAuditLogs(
            $citizenModel,
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items'      => DashboardCitizenAuditLogResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.citizen_audit_logs_retrieved');
    }
}
