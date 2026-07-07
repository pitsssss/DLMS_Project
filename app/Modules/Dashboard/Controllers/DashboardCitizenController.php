<?php

namespace App\Modules\Dashboard\Controllers;

use App\Enums\ProfileStatus;
use App\Http\Controllers\Controller;
use App\Modules\Fines\Resources\FineResource;
use App\Modules\Dashboard\Requests\StoreDashboardCitizenRequest;
use App\Modules\Dashboard\Requests\UpdateDashboardCitizenRequest;
use App\Modules\Dashboard\Resources\DashboardApplicationResource;
use App\Modules\Dashboard\Resources\DashboardCitizenResource;
use App\Modules\Dashboard\Services\DashboardCitizenService;
use App\Modules\Licenses\Resources\LicenseResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardCitizenController extends Controller
{
    public function index(Request $request, DashboardCitizenService $citizens)
    {
        $filters = $request->validate([
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'profile_status' => ['sometimes', 'nullable', Rule::enum(ProfileStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $citizens->paginate($filters, $perPage);

        return $this->successResponse([
            'items' => DashboardCitizenResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.citizens_list_retrieved');
    }

    public function search(Request $request, DashboardCitizenService $citizens)
    {
        $term = (string) $request->input('search_term', '');

        $records = $citizens->searchCitizens($term);

        return $this->successResponse(
            DashboardCitizenResource::collection($records)->resolve(),
            'messages.dashboard.citizens_list_retrieved'
        );
    }

    public function profileStatuses(DashboardCitizenService $citizens)
    {
        return $this->successResponse(
            $citizens->profileStatuses(),
            'messages.dashboard.profile_statuses_retrieved'
        );
    }

    public function applications(Request $request, int $user, DashboardCitizenService $citizens)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $citizens->citizenApplications(
            $citizens->getCitizen($user),
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items' => DashboardApplicationResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'messages.dashboard.citizen_applications_retrieved');
    }

    public function licenses(Request $request, int $user, DashboardCitizenService $citizens)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $citizens->citizenLicenses(
            $citizens->getCitizen($user),
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items' => LicenseResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function fines(Request $request, int $user, DashboardCitizenService $citizens)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $citizens->citizenFines(
            $citizens->getCitizen($user),
            (int) ($validated['per_page'] ?? 20)
        );

        return $this->successResponse([
            'items' => FineResource::collection($paginator->items())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function update(UpdateDashboardCitizenRequest $request, int $user, DashboardCitizenService $citizens)
    {
        $citizen = $citizens->update(
            $request->user(),
            $citizens->getCitizen($user),
            $request->validated()
        );

        return $this->successResponse(
            new DashboardCitizenResource($citizen->load('role')),
            'messages.dashboard.citizen_updated'
        );
    }


}
